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

    /** @return array{total_bytes: int, available_bytes: int, swap_used_bytes: int, oom_kills: ?int, swap_in_pages: ?int, swap_out_pages: ?int}|null */
    public function collect(): ?array
    {
        $memory = $this->host->memInfo();

        if ($memory === null) {
            return null;
        }

        return [
            ...$memory,
            ...($this->host->vmStat() ?? ['oom_kills' => null, 'swap_in_pages' => null, 'swap_out_pages' => null]),
        ];
    }
}
