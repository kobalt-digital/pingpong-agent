<?php

namespace KobaltDigital\PingPong\Tests\Fakes;

use KobaltDigital\PingPong\Host;

class FakeHost extends Host
{
    /**
     * @param  array{'1m': float, '5m': float, '15m': float}|null  $loadAverage
     * @param  array{total_bytes: int, available_bytes: int, swap_used_bytes: int}|null  $memInfo
     */
    public function __construct(
        private ?array $loadAverage = ['1m' => 0.81, '5m' => 0.62, '15m' => 0.55],
        private ?array $memInfo = ['total_bytes' => 8244207616, 'available_bytes' => 1310720000, 'swap_used_bytes' => 0],
        private ?int $cores = 4,
    ) {}

    public function loadAverage(): ?array
    {
        return $this->loadAverage;
    }

    public function memInfo(): ?array
    {
        return $this->memInfo;
    }

    public function cores(): ?int
    {
        return $this->cores;
    }
}
