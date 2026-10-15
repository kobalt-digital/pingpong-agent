<?php

namespace KobaltDigital\PingPong;

use Illuminate\Support\Facades\Log;

class TickInterval
{
    public const DEFAULT_MINUTES = 1;

    public readonly int $minutes;

    public function __construct()
    {
        $this->minutes = $this->configuredMinutes();
    }

    public function cron(): string
    {
        return $this->minutes === 1
            ? '* * * * *'
            : "*/{$this->minutes} * * * *";
    }

    /**
     * Only divisors of an hour give a cron step that ticks at an even pace;
     * a step of 7 would tick at :56 and again at :00.
     */
    private function configuredMinutes(): int
    {
        $configured = config('pingpong-agent.tick_minutes', self::DEFAULT_MINUTES);

        $minutes = filter_var($configured, FILTER_VALIDATE_INT);

        if ($minutes === false || $minutes < 1 || 60 % $minutes !== 0) {
            Log::warning("PingPong Agent ignores tick_minutes {$configured}; it must divide 60. Ticking every minute.");

            return self::DEFAULT_MINUTES;
        }

        return $minutes;
    }
}
