<?php

namespace KobaltDigital\PingPong\Scheduling;

class ScheduledTask
{
    public function __construct(
        public readonly string $slug,
        public readonly string $cron,
        public readonly string $timezone,
    ) {}
}
