<?php

namespace KobaltDigital\PingPong\Signals;

use KobaltDigital\PingPong\Contracts\MachineSignal;
use KobaltDigital\PingPong\Host;

/**
 * CPU time since boot, in jiffies. PingPong takes the difference between two
 * ticks of the same server.
 */
class Cpu implements MachineSignal
{
    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'cpu';
    }

    /** @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null */
    public function collect(): ?array
    {
        return $this->host->cpuTimes();
    }
}
