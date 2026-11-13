<?php

namespace KobaltDigital\PingPong\Delivery;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Remembers, per server, the stretch in which nothing reached PingPong, so
 * the first tick that gets through can say how long and why.
 *
 * @phpstan-type Report array{
 *     unreachable_since: string,
 *     unreachable_until: string,
 *     failed_attempts: int,
 *     last_error: string,
 *     last_error_message: string,
 * }
 */
class Connectivity
{
    public const TIME_FORMAT = 'Y-m-d\TH:i:sp';

    public function recordFailure(Failure $failure): void
    {
        try {
            $this->file()->update(function (array $state) use ($failure) {
                $now = Date::now()->utc()->format(self::TIME_FORMAT);

                $report = $this->toReport($state);

                $since = $report['unreachable_since'] ?? $now;

                // PingPong refuses a whole tick whose gap ends before it starts,
                // which a clock set back during the gap would otherwise send.
                return [
                    'unreachable_since' => $since,
                    'unreachable_until' => max($since, $now),
                    'failed_attempts' => ($report['failed_attempts'] ?? 0) + 1,
                    'last_error' => $failure->kind->value,
                    'last_error_message' => $failure->message,
                ];
            });
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not remember that PingPong was unreachable.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return ?Report
     */
    public function report(): ?array
    {
        try {
            return $this->toReport($this->file()->read());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Forgets the gap PingPong accepted. A request that failed while the tick
     * carrying it was underway starts the next gap, from its own failure.
     *
     * @param  Report  $reported
     */
    public function clear(array $reported): void
    {
        try {
            $this->file()->update(function (array $state) use ($reported) {
                $current = $this->toReport($state);

                if ($current === null || $current === $reported) {
                    return [];
                }

                return [
                    ...$current,
                    'unreachable_since' => $current['unreachable_until'],
                    'failed_attempts' => max(1, $current['failed_attempts'] - $reported['failed_attempts']),
                ];
            });
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not forget a reported connectivity gap.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<mixed>  $state
     * @return ?Report
     */
    private function toReport(array $state): ?array
    {
        $since = $state['unreachable_since'] ?? null;
        $until = $state['unreachable_until'] ?? null;
        $failedAttempts = $state['failed_attempts'] ?? null;
        $lastError = $state['last_error'] ?? null;
        $lastErrorMessage = $state['last_error_message'] ?? null;

        if (! is_string($since) || ! is_string($until) || ! is_int($failedAttempts)) {
            return null;
        }

        if (! is_string($lastError) || ! is_string($lastErrorMessage)) {
            return null;
        }

        return [
            'unreachable_since' => $since,
            'unreachable_until' => $until,
            'failed_attempts' => $failedAttempts,
            'last_error' => $lastError,
            'last_error_message' => $lastErrorMessage,
        ];
    }

    private function file(): StateFile
    {
        return StateFile::named('connectivity');
    }
}
