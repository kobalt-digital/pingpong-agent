<?php

namespace KobaltDigital\PingPong\Signals;

use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Host;

class Memory implements Collector
{
    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'memory';
    }

    /** @return array{total_bytes: int, available_bytes: int, swap_used_bytes: int}|null */
    public function collect(): ?array
    {
        return $this->host->memInfo();
    }
}
