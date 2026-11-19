<?php

namespace KobaltDigital\PingPong;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The app's own numbers, read once per tick on the server that ticks.
 *
 * An app registers a metric once, usually in a service provider, with a
 * closure that reads it. Nothing in a request or a job sends a metric, so
 * what reaches PingPong is bounded by the metrics registered times the ticks.
 *
 * Bad input costs a logged warning, never the app or the tick: a name or a
 * unit PingPong would refuse, or a registration beyond `MAX_METRICS`, is
 * ignored when it is registered; a closure that throws or returns something
 * other than a finite number is left out of that tick.
 */
class Metrics
{
    public const MAX_METRICS = 20;

    public const MAX_NAME_LENGTH = 64;

    /** Larger than any count or duration an app means, small enough that a float keeps it exact enough. */
    public const MAX_ABSOLUTE_VALUE = 1e15;

    /** Lower case words split by dots, like `orders.pending` or `queue.import_wait`. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/';

    public const UNITS = ['count', 'percent', 'ms', 'seconds', 'bytes'];

    /** @var array<string, array{resolve: Closure(): mixed, unit: string}> */
    private array $metrics = [];

    /** @param  Closure(): mixed  $resolve */
    public function register(string $name, Closure $resolve, string $unit = 'count'): void
    {
        if (strlen($name) > self::MAX_NAME_LENGTH || preg_match(self::NAME_PATTERN, $name) !== 1) {
            Log::warning("PingPong Agent ignores metric \"{$name}\": a name is lower case words split by dots, at most ".self::MAX_NAME_LENGTH.' characters.');

            return;
        }

        if (! in_array($unit, self::UNITS, true)) {
            Log::warning("PingPong Agent ignores metric \"{$name}\": its unit is one of ".implode(', ', self::UNITS).'.');

            return;
        }

        if (! isset($this->metrics[$name]) && count($this->metrics) >= self::MAX_METRICS) {
            Log::warning("PingPong Agent ignores metric \"{$name}\": an app reports at most ".self::MAX_METRICS.' metrics.');

            return;
        }

        $this->metrics[$name] = ['resolve' => $resolve, 'unit' => $unit];
    }

    /**
     * Every registered metric that read a usable number this time, or null
     * while none is registered.
     *
     * @return list<array{name: string, value: int|float, unit: string}>|null
     */
    public function read(): ?array
    {
        if ($this->metrics === []) {
            return null;
        }

        $values = [];

        foreach ($this->metrics as $name => $metric) {
            $value = $this->value($name, $metric['resolve']);

            if ($value !== null) {
                $values[] = ['name' => $name, 'value' => $value, 'unit' => $metric['unit']];
            }
        }

        return $values;
    }

    /** @param  Closure(): mixed  $resolve */
    private function value(string $name, Closure $resolve): int|float|null
    {
        try {
            $value = $resolve();
        } catch (Throwable $exception) {
            Log::warning("PingPong Agent could not read metric \"{$name}\".", ['exception' => $exception->getMessage()]);

            return null;
        }

        if (! is_int($value) && ! is_float($value)) {
            Log::warning("PingPong Agent left out metric \"{$name}\": it read ".get_debug_type($value).', not a number.');

            return null;
        }

        if (! is_finite((float) $value) || abs($value) > self::MAX_ABSOLUTE_VALUE) {
            Log::warning("PingPong Agent left out metric \"{$name}\": it read a number out of range.");

            return null;
        }

        return $value;
    }
}
