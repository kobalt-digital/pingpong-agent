<?php

namespace KobaltDigital\PingPong\Machine;

use KobaltDigital\PingPong\Host;

/**
 * What the machine says about itself, read the way the Laravel agent reads
 * it so PingPong judges both the same: load, memory, CPU, pressure, the disk
 * and the host facts (uptime, a reboot it waits for, pending updates).
 *
 * Only the methods of `Host` that read a file or parse text are used here;
 * the ones that go through a Laravel facade (`inodes`, `upgradable`,
 * `fleet`) are done with the Runner instead, since the phar has no Laravel.
 */
class Vitals
{
    public const DF_TIMEOUT_SECONDS = 2;

    public const APT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private Host $host,
        private Runner $runner,
        private string $oomJournal = '/etc/pingpong-machine/oom-kills.jsonl',
    ) {}

    /**
     * Read the recent kernel kills exported by the system installer's collector.
     *
     * @return list<array{at: int, pid: int, process: string, scope: string, uid: ?int, rss_bytes: int}>
     */
    public function oomKills(int $now): array
    {
        return (new OomKills)->read($this->oomJournal, $now);
    }

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
     * Ubuntu's update-notifier counts the updates; where it does not run
     * (Debian, a minimal Ubuntu) apt counts them, as `HostState` does.
     *
     * @return array{uptime_seconds: ?int, reboot_required: ?bool, reboot_packages: ?list<string>, updates_pending: ?int, updates_security: ?int, update_packages: ?list<array{name: string, version: ?string, security: bool}>}
     */
    public function host(State $state): array
    {
        $updates = $this->host->updates();
        $upgradable = $updates === null || $updates['pending'] > 0 ? $this->upgradable($state) : null;
        $updates ??= $upgradable === null ? null : ['pending' => $upgradable['pending'], 'security' => $upgradable['security']];

        return [
            'uptime_seconds' => $this->host->uptime(),
            'reboot_required' => $this->host->rebootRequired(),
            'reboot_packages' => $this->host->rebootPackages(),
            'updates_pending' => $updates['pending'] ?? null,
            'updates_security' => $updates['security'] ?? null,
            'update_packages' => ($updates['pending'] ?? 0) > 0 ? ($upgradable['packages'] ?? null) : null,
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
     * the updates change (`Host::updatesStamp()`: apt's lists or the
     * installed packages). Null where apt is missing or has no lists.
     *
     * @return array{pending: int, security: int, packages: list<array{name: string, version: ?string, security: bool}>}|null
     */
    private function upgradable(State $state): ?array
    {
        $stamp = $this->host->updatesStamp();
        $remembered = $state->get('upgradable');

        if ($stamp !== null && is_array($remembered) && ($remembered['stamp'] ?? null) === $stamp && is_array($remembered['upgradable'] ?? null)) {
            /** @var array{pending: int, security: int, packages: list<array{name: string, version: ?string, security: bool}>} */
            return $remembered['upgradable'];
        }

        $apt = $this->runner->find('apt');

        if ($apt === null || ! $this->host->hasAptLists()) {
            return null;
        }

        $result = $this->runner->run([$apt, 'list', '--upgradable'], self::APT_TIMEOUT_SECONDS);

        if ($result === null || $result['exit'] !== 0) {
            return null;
        }

        $upgradable = $this->host->parseUpgradableSummary($result['output']);

        if ($stamp !== null) {
            $state->set('upgradable', ['stamp' => $stamp, 'upgradable' => $upgradable]);
        }

        return $upgradable;
    }
}
