<?php

namespace KobaltDigital\PingPong\Delivery;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Check-ins that did not reach PingPong, kept on disk per server until a
 * tick gets through and replays them. Only Check-ins: a late tick, canary
 * or handshake tells PingPong nothing it does not learn from the next one.
 *
 * @phpstan-type Entry array{
 *     id: string,
 *     queued_at: int,
 *     payload: array<string, mixed>,
 * }
 */
class Outbox
{
    public const MAX_ENTRIES = 500;

    public const MAX_AGE_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(array $payload): void
    {
        try {
            $this->change(fn (array $entries) => [...$entries, [
                'id' => Str::uuid()->toString(),
                'queued_at' => Date::now()->getTimestamp(),
                'payload' => $payload,
            ]]);
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not keep a Check-in to send later.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return list<Entry>
     */
    public function oldest(int $limit): array
    {
        try {
            if ($this->entries($this->file()->read()) === []) {
                return [];
            }

            return array_slice($this->change(fn (array $entries) => $entries), 0, $limit);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Read only, so counting never waits longer than another process's
     * write. An outbox that cannot be read counts as empty, so a broken file
     * never holds PingPong's flush window open.
     */
    public function pending(): int
    {
        try {
            return count($this->withinBounds($this->entries($this->file()->read())));
        } catch (Throwable) {
            return 0;
        }
    }

    public function remove(string $id): void
    {
        try {
            $this->change(fn (array $entries) => array_values(array_filter(
                $entries,
                fn (array $entry) => $entry['id'] !== $id,
            )));
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not remove a replayed Check-in from its outbox.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Applies the change and the bounds under one lock. Dropping is logged
     * once, and again only after the outbox has emptied, so an outage that
     * lasts a day does not log a warning every minute.
     *
     * @param  Closure(list<Entry>): list<Entry>  $change
     * @return list<Entry>
     */
    private function change(Closure $change): array
    {
        $kept = [];

        $this->file()->update(function (array $state) use ($change, &$kept) {
            $entries = $change($this->entries($state));

            $kept = $this->withinBounds($entries);

            $dropped = count($entries) - count($kept);

            $wasDropping = ($state['dropping'] ?? false) === true;

            if ($dropped > 0 && ! $wasDropping) {
                Log::warning('PingPong Agent dropped the oldest Check-ins it kept while PingPong was unreachable.', [
                    'dropped' => $dropped,
                    'max_entries' => self::MAX_ENTRIES,
                    'max_age_seconds' => self::MAX_AGE_SECONDS,
                ]);
            }

            if ($kept === []) {
                return [];
            }

            return [
                'dropping' => $wasDropping || $dropped > 0,
                'entries' => $kept,
            ];
        });

        return $kept;
    }

    /**
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private function withinBounds(array $entries): array
    {
        $oldestAllowed = Date::now()->getTimestamp() - self::MAX_AGE_SECONDS;

        $recent = array_filter($entries, fn (array $entry) => $entry['queued_at'] >= $oldestAllowed);

        return array_slice($recent, -self::MAX_ENTRIES);
    }

    /**
     * @param  array<mixed>  $state
     * @return list<Entry>
     */
    private function entries(array $state): array
    {
        $entries = $state['entries'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $valid = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (! is_string($entry['id'] ?? null) || ! is_int($entry['queued_at'] ?? null)) {
                continue;
            }

            $payload = $this->payload($entry['payload'] ?? null);

            if ($payload === null) {
                continue;
            }

            $valid[] = [
                'id' => $entry['id'],
                'queued_at' => $entry['queued_at'],
                'payload' => $payload,
            ];
        }

        return $valid;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function payload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $fields = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $fields[$key] = $value;
        }

        return $fields;
    }

    private function file(): StateFile
    {
        return StateFile::named('outbox');
    }
}
