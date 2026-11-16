<?php

namespace KobaltDigital\PingPong\Signals;

use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Host;

/**
 * Pressure stall information: the percentage of the last minute in which
 * tasks waited on CPU, memory or IO.
 */
class Pressure implements Collector
{
    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'pressure';
    }

    /** @return array{cpu_some: ?float, memory_some: ?float, memory_full: ?float, io_some: ?float, io_full: ?float}|null */
    public function collect(): ?array
    {
        return $this->host->pressure();
    }
}
