<?php

namespace KobaltDigital\PingPong\Machine;

use KobaltDigital\PingPong\Host;

/**
 * What the machine says about itself, read the way the Laravel agent reads
 * it so PingPong judges both the same: load, memory, CPU, pressure, the disks
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

    /**
     * Filesystems on a disk of the machine itself. Anything else (tmpfs,
     * overlay, squashfs, NFS, FUSE) either cannot fill a disk or can hang
     * whoever asks it how full it is.
     */
    public const LOCAL_FILESYSTEMS = ['ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'f2fs', 'jfs', 'reiserfs', 'bcachefs'];

    public const MAX_DISKS = 32;

    public function __construct(
        private Host $host,
        private Runner $runner,
        private string $oomJournal = '/etc/pingpong-machine/oom-kills.jsonl',
        private string $mounts = '/proc/mounts',
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
     * @return array{load: ?array{'1m': float, '5m': float, '15m': float}, memory: ?array<string, ?int>, cpu: ?array<string, int>, pressure: ?array<string, ?float>, disk: ?array{free_bytes: int, total_bytes: int, inodes_total: ?int, inodes_free: ?int}, disks: ?list<array{mount: string, type: string, free_bytes: int, total_bytes: int, inodes_total: ?int, inodes_free: ?int}>}
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
            'disks' => $this->disks(),
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
     * Every local filesystem once, by where it is mounted. The filesystem
     * keeps how full it is itself, so this costs the same for a disk with a
     * million files as for an empty one. Null where none can be read.
     *
     * @return list<array{mount: string, type: string, free_bytes: int, total_bytes: int, inodes_total: ?int, inodes_free: ?int}>|null
     */
    public function disks(): ?array
    {
        $mounts = $this->localMounts();

        if ($mounts === []) {
            return null;
        }

        $inodes = $this->inodesByMount(array_keys($mounts));
        $disks = [];

        foreach ($mounts as $mount => $type) {
            $free = @disk_free_space($mount);
            $total = @disk_total_space($mount);

            if ($free === false || $total === false || $total <= 0) {
                continue;
            }

            $disks[] = [
                'mount' => $mount,
                'type' => $type,
                'free_bytes' => (int) $free,
                'total_bytes' => (int) $total,
                'inodes_total' => $inodes[$mount]['total'] ?? null,
                'inodes_free' => $inodes[$mount]['free'] ?? null,
            ];
        }

        return $disks === [] ? null : $disks;
    }

    /**
     * The local filesystems from `/proc/mounts`, where a space in a path is
     * written as `\040`. A device mounted twice (a bind mount, a btrfs
     * subvolume) is one disk, named by its shortest mount point.
     *
     * @return array<string, string> the type of every mount point, by mount point
     */
    public function localMounts(): array
    {
        $byDevice = [];

        foreach (preg_split('/\R/', (string) @file_get_contents($this->mounts)) ?: [] as $line) {
            $fields = explode(' ', $line);

            if (count($fields) < 3 || ! in_array($fields[2], self::LOCAL_FILESYSTEMS, true)) {
                continue;
            }

            $device = stripcslashes($fields[0]);
            $mount = stripcslashes($fields[1]);
            $known = $byDevice[$device] ?? null;

            if ($known === null || strlen($mount) < strlen($known['mount'])) {
                $byDevice[$device] = ['mount' => $mount, 'type' => $fields[2]];
            }
        }

        $mounts = array_column($byDevice, 'type', 'mount');
        ksort($mounts, SORT_STRING);

        return array_slice($mounts, 0, self::MAX_DISKS, true);
    }

    /**
     * The inodes of every mount point in one `df`, since PHP cannot ask for
     * them. A filesystem that keeps no count (btrfs says 0) has none.
     *
     * @param  list<string>  $mounts
     * @return array<string, array{total: int, free: int}>
     */
    private function inodesByMount(array $mounts): array
    {
        $df = $this->runner->find('df');

        if ($df === null) {
            return [];
        }

        $result = $this->runner->run([$df, '-iP', ...$mounts], self::DF_TIMEOUT_SECONDS);

        return $result === null ? [] : $this->parseInodesByMount($result['output']);
    }

    /**
     * `df -iP` prints one filesystem a line, never wrapped: its name, the
     * inodes, used, free, the share used and where it is mounted, which may
     * hold spaces.
     *
     * @return array<string, array{total: int, free: int}>
     */
    public function parseInodesByMount(string $output): array
    {
        $inodes = [];

        foreach (array_slice(preg_split('/\R/', trim($output)) ?: [], 1) as $line) {
            if (preg_match('/^\S+\s+(\d+)\s+\d+\s+(\d+)\s+\d+%\s+(.+)$/', $line, $match) !== 1 || (int) $match[1] === 0) {
                continue;
            }

            $inodes[$match[3]] = ['total' => (int) $match[1], 'free' => (int) $match[2]];
        }

        return $inodes;
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
