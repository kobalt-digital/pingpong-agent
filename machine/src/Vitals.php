<?php

namespace KobaltDigital\PingPong\Machine;

use KobaltDigital\PingPong\Host;

/**
 * What the machine says about itself, read the way the Laravel agent reads
 * it so PingPong judges both the same: load, memory, CPU, pressure, the disk
 * and the host facts (uptime, a reboot it waits for, pending updates).
 *
 * Only the methods of `Host` that read a file or parse text are used here;
 * the ones that go through a Laravel facade (`inodes`, `upgradablePackages`,
 * `fleet`) are done with the Runner instead, since the phar has no Laravel.
 */
class Vitals
{
    public const DF_TIMEOUT_SECONDS = 2;

    public const APT_TIMEOUT_SECONDS = 10;

    public function __construct(private Host $host, private Runner $runner) {}

    /**
     * @return array{load: ?array{'1m': float, '5m': float, '15m': float}, memory: ?array<string, ?int>, cpu: ?array<string, int>, pressure: ?array<string, ?float>, disk: ?array{free_bytes: int, total_bytes: int, inodes_total: ?int, inodes_free: ?int}}
     */
    public function signals(string $diskPath): array
    {
        $memory = $this->host->memInfo();

        return [
            'load' => $this->host->loadAverage(),
            'memory' => $memory === null ? null : [...$memory, ...($this->host->vmStat() ?? [])],
            'cpu' => $this->host->cpuTimes(),
            'pressure' => $this->host->pressure(),
            'disk' => $this->disk($diskPath),
        ];
    }

    /**
     * @return array{uptime_seconds: ?int, reboot_required: ?bool, reboot_packages: ?list<string>, updates_pending: ?int, updates_security: ?int, update_packages: ?list<array{name: string, version: ?string, security: bool}>}
     */
    public function host(State $state): array
    {
        $updates = $this->host->updates();

        return [
            'uptime_seconds' => $this->host->uptime(),
            'reboot_required' => $this->host->rebootRequired(),
            'reboot_packages' => $this->host->rebootPackages(),
            'updates_pending' => $updates['pending'] ?? null,
            'updates_security' => $updates['security'] ?? null,
            'update_packages' => ($updates['pending'] ?? 0) > 0 ? $this->updatePackages($state) : null,
        ];
    }

    /**
     * @return array{machine_id: ?string, os_name: ?string, kernel: ?string, cores: ?int}
     */
    public function identity(): array
    {
        return [
            'machine_id' => $this->host->machineId(),
            'os_name' => $this->host->osName(),
            'kernel' => $this->host->kernel(),
            'cores' => $this->host->cores(),
        ];
    }

    /**
     * @return array{free_bytes: int, total_bytes: int, inodes_total: ?int, inodes_free: ?int}|null
     */
    public function disk(string $path): ?array
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false) {
            return null;
        }

        $inodes = null;
        $df = $this->runner->find('df');

        if ($df !== null) {
            $result = $this->runner->run([$df, '-iP', $path], self::DF_TIMEOUT_SECONDS);
            $inodes = $result !== null && $result['exit'] === 0 ? $this->host->parseInodes($result['output']) : null;
        }

        return [
            'free_bytes' => (int) $free,
            'total_bytes' => (int) $total,
            'inodes_total' => $inodes['total'] ?? null,
            'inodes_free' => $inodes['free'] ?? null,
        ];
    }

    /**
     * `apt list --upgradable` takes a moment, so its answer is kept until
     * update-notifier counts again (whenever apt's lists or the installed
     * packages change).
     *
     * @return list<array{name: string, version: ?string, security: bool}>|null
     */
    private function updatePackages(State $state): ?array
    {
        $stamp = $this->host->updatesStamp();
        $remembered = $state->get('update_packages');

        if ($stamp !== null && is_array($remembered) && ($remembered['stamp'] ?? null) === $stamp && is_array($remembered['packages'] ?? null)) {
            /** @var list<array{name: string, version: ?string, security: bool}> */
            return $remembered['packages'];
        }

        $apt = $this->runner->find('apt');

        if ($apt === null) {
            return null;
        }

        $result = $this->runner->run([$apt, 'list', '--upgradable'], self::APT_TIMEOUT_SECONDS);

        if ($result === null || $result['exit'] !== 0) {
            return null;
        }

        $packages = $this->host->parseUpgradable($result['output']);

        if ($stamp !== null) {
            $state->set('update_packages', ['stamp' => $stamp, 'packages' => $packages]);
        }

        return $packages;
    }
}
