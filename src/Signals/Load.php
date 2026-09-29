<?php

namespace KobaltDigital\PingPong\Signals;

use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Host;

class Load implements Collector
{
    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'load';
    }

    /** @return array{'1m': float, '5m': float, '15m': float}|null */
    public function collect(): ?array
    {
        return $this->host->loadAverage();
    }
}
