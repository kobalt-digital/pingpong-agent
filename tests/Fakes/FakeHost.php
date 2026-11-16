<?php

namespace KobaltDigital\PingPong\Tests\Fakes;

use KobaltDigital\PingPong\Host;

class FakeHost extends Host
{
    /**
     * @param  array{'1m': float, '5m': float, '15m': float}|null  $loadAverage
     * @param  array{total_bytes: int, available_bytes: int, swap_used_bytes: int}|null  $memInfo
     * @param  array<string, string>  $executables
     * @param  list<string>  $livePidFiles
     * @param  array{oom_kills: ?int, swap_in_pages: ?int, swap_out_pages: ?int}|null  $vmStat
     * @param  array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null  $cpuTimes
     * @param  array{cpu_some: ?float, memory_some: ?float, memory_full: ?float, io_some: ?float, io_full: ?float}|null  $pressure
     * @param  array{total: int, free: int}|null  $inodes
     * @param  list<string>|null  $rebootPackages
     * @param  array{pending: int, security: int}|null  $updates
     * @param  list<array{name: string, version: ?string, security: bool}>|null  $updatePackages
     */
    public function __construct(
        private ?array $loadAverage = ['1m' => 0.81, '5m' => 0.62, '15m' => 0.55],
        private ?array $memInfo = ['total_bytes' => 8244207616, 'available_bytes' => 1310720000, 'swap_used_bytes' => 0],
        private ?int $cores = 4,
        private array $executables = [],
        private array $livePidFiles = [],
        private ?array $vmStat = ['oom_kills' => 2, 'swap_in_pages' => 1843, 'swap_out_pages' => 5120],
        private ?array $cpuTimes = ['user' => 15595036, 'nice' => 4132, 'system' => 8155061, 'idle' => 341748387, 'iowait' => 347222, 'irq' => 0, 'softirq' => 1950356, 'steal' => 1013901],
        private ?array $pressure = ['cpu_some' => 4.11, 'memory_some' => 0.12, 'memory_full' => 0.03, 'io_some' => 1.27, 'io_full' => 0.84],
        private ?array $inodes = ['total' => 6553600, 'free' => 6141255],
        private ?string $machineId = '4f9c2b7e1a8d4e6f9b3c5a7d2e1f0c8b',
        private ?string $osName = 'Ubuntu 24.04.1 LTS',
        private ?string $kernel = '6.8.0-45-generic',
        private ?int $uptime = 3542791,
        private ?bool $rebootRequired = true,
        private ?array $rebootPackages = ['linux-image-6.8.0-49-generic', 'libc6'],
        private ?array $updates = ['pending' => 14, 'security' => 6],
        private ?array $updatePackages = [
            ['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5', 'security' => true],
            ['name' => 'nginx', 'version' => '1.24.0-2ubuntu7.2', 'security' => false],
        ],
        private ?string $fleet = null,
        private ?int $updatesStamp = 1763328600,
    ) {}

    public function upgradablePackages(): ?array
    {
        return $this->updatePackages;
    }

    public function updatesStamp(): ?int
    {
        return $this->updatesStamp;
    }

    public function fleet(): ?string
    {
        return $this->fleet;
    }

    public function loadAverage(): ?array
    {
        return $this->loadAverage;
    }

    public function memInfo(): ?array
    {
        return $this->memInfo;
    }

    public function vmStat(): ?array
    {
        return $this->vmStat;
    }

    public function cpuTimes(): ?array
    {
        return $this->cpuTimes;
    }

    public function pressure(): ?array
    {
        return $this->pressure;
    }

    public function inodes(string $path): ?array
    {
        return $this->inodes;
    }

    public function machineId(): ?string
    {
        return $this->machineId;
    }

    public function osName(): ?string
    {
        return $this->osName;
    }

    public function kernel(): ?string
    {
        return $this->kernel;
    }

    public function uptime(): ?int
    {
        return $this->uptime;
    }

    public function rebootRequired(): ?bool
    {
        return $this->rebootRequired;
    }

    public function rebootPackages(): ?array
    {
        return $this->rebootPackages;
    }

    public function updates(): ?array
    {
        return $this->updates;
    }

    public function cores(): ?int
    {
        return $this->cores;
    }

    public function executable(string $name): ?string
    {
        return $this->executables[$name] ?? null;
    }

    public function pidFileRuns(string $path): bool
    {
        return in_array($path, $this->livePidFiles, true);
    }
}
