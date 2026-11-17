<?php

namespace KobaltDigital\PingPong;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads operating system facts that only some platforms offer. Every method
 * answers null when this one does not, so callers never have to know whether
 * they run on Linux, macOS or a locked down shared host.
 */
class Host
{
    public const DF_TIMEOUT_SECONDS = 2;

    /** The longest operating system name or kernel release PingPong keeps. */
    public const MAX_NAME_LENGTH = 100;

    /** The most packages that asked for a reboot PingPong keeps. */
    public const MAX_REBOOT_PACKAGES = 50;

    /** The most pending updates sent by name. */
    public const MAX_UPDATE_PACKAGES = 500;

    /** How long `apt list --upgradable` may take before it is given up on. */
    public const APT_TIMEOUT_SECONDS = 10;

    /** How long the ECS task metadata endpoint may take; it answers locally. */
    public const ECS_METADATA_TIMEOUT_SECONDS = 1;

    /** The longest fleet name PingPong keeps. */
    public const MAX_FLEET_LENGTH = 255;

    /** The variable ECS sets in every container of a task (Fargate and EC2). */
    public const ECS_METADATA_VARIABLE = 'ECS_CONTAINER_METADATA_URI_V4';

    /** @var array<string, ?string> the fleet per metadata endpoint, asked once per process */
    private static array $fleets = [];

    public const RUN_DIRECTORY = '/var/run';

    public const REBOOT_REQUIRED_FILE = '/var/run/reboot-required';

    public const REBOOT_PACKAGES_FILE = '/var/run/reboot-required.pkgs';

    public const UPDATES_AVAILABLE_FILE = '/var/lib/update-notifier/updates-available';

    /** Where apt keeps the package lists of its sources, which `apt update` refreshes. */
    public const APT_LISTS_DIRECTORY = '/var/lib/apt/lists';

    /** What dpkg has installed, rewritten on every install, upgrade and removal. */
    public const DPKG_STATUS_FILE = '/var/lib/dpkg/status';

    /** @return array{'1m': float, '5m': float, '15m': float}|null */
    public function loadAverage(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();

            if (is_array($load)) {
                return $this->loadShape((float) $load[0], (float) $load[1], (float) $load[2]);
            }
        }

        $contents = $this->read('/proc/loadavg');

        if ($contents === null) {
            return null;
        }

        return $this->parseLoadAverage($contents);
    }

    /** @return array{'1m': float, '5m': float, '15m': float}|null */
    public function parseLoadAverage(string $contents): ?array
    {
        $parts = preg_split('/\s+/', trim($contents));

        if ($parts === false || count($parts) < 3) {
            return null;
        }

        if (! is_numeric($parts[0]) || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
            return null;
        }

        return $this->loadShape((float) $parts[0], (float) $parts[1], (float) $parts[2]);
    }

    /** @return array{total_bytes: int, available_bytes: int, swap_used_bytes: int}|null */
    public function memInfo(): ?array
    {
        $contents = $this->read('/proc/meminfo');

        if ($contents === null) {
            return null;
        }

        return $this->parseMemInfo($contents);
    }

    /** @return array{total_bytes: int, available_bytes: int, swap_used_bytes: int}|null */
    public function parseMemInfo(string $contents): ?array
    {
        preg_match_all('/^(\w+):\s+(\d+) kB/m', $contents, $matches, PREG_SET_ORDER);

        $kilobytes = [];

        foreach ($matches as $match) {
            $kilobytes[$match[1]] = (int) $match[2];
        }

        foreach (['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree'] as $key) {
            if (! isset($kilobytes[$key])) {
                return null;
            }
        }

        return [
            'total_bytes' => $kilobytes['MemTotal'] * 1024,
            'available_bytes' => $kilobytes['MemAvailable'] * 1024,
            'swap_used_bytes' => ($kilobytes['SwapTotal'] - $kilobytes['SwapFree']) * 1024,
        ];
    }

    /** @return array{oom_kills: ?int, swap_in_pages: ?int, swap_out_pages: ?int}|null */
    public function vmStat(): ?array
    {
        $contents = $this->read('/proc/vmstat');

        if ($contents === null) {
            return null;
        }

        return $this->parseVmStat($contents);
    }

    /**
     * Counters since boot. A kernel that lacks one (`oom_kill` came in 4.13)
     * leaves that one null.
     *
     * @return array{oom_kills: ?int, swap_in_pages: ?int, swap_out_pages: ?int}
     */
    public function parseVmStat(string $contents): array
    {
        preg_match_all('/^(\w+) (\d+)\r?$/m', $contents, $matches, PREG_SET_ORDER);

        $counters = [];

        foreach ($matches as $match) {
            $counters[$match[1]] = (int) $match[2];
        }

        return [
            'oom_kills' => $counters['oom_kill'] ?? null,
            'swap_in_pages' => $counters['pswpin'] ?? null,
            'swap_out_pages' => $counters['pswpout'] ?? null,
        ];
    }

    /** @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null */
    public function cpuTimes(): ?array
    {
        $contents = $this->read('/proc/stat');

        if ($contents === null) {
            return null;
        }

        return $this->parseCpuTimes($contents);
    }

    /**
     * Jiffies since boot from the line that adds up every CPU, so PingPong
     * takes the difference between two ticks.
     *
     * @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null
     */
    public function parseCpuTimes(string $contents): ?array
    {
        if (preg_match('/^cpu +(\d+(?: +\d+){7,})/m', $contents, $match) !== 1) {
            return null;
        }

        $values = array_map(intval(...), explode(' ', (string) preg_replace('/ +/', ' ', $match[1])));

        return [
            'user' => $values[0],
            'nice' => $values[1],
            'system' => $values[2],
            'idle' => $values[3],
            'iowait' => $values[4],
            'irq' => $values[5],
            'softirq' => $values[6],
            'steal' => $values[7],
        ];
    }

    /**
     * The share of the last minute in which tasks waited on CPU, memory or
     * IO. Null without `/proc/pressure` (a kernel before 4.20, or PSI
     * switched off), and when none of it could be read.
     *
     * @return array{cpu_some: ?float, memory_some: ?float, memory_full: ?float, io_some: ?float, io_full: ?float}|null
     */
    public function pressure(): ?array
    {
        if (! @is_dir('/proc/pressure')) {
            return null;
        }

        $cpu = $this->parsePressure((string) $this->read('/proc/pressure/cpu'));
        $memory = $this->parsePressure((string) $this->read('/proc/pressure/memory'));
        $io = $this->parsePressure((string) $this->read('/proc/pressure/io'));

        $pressure = [
            'cpu_some' => $cpu['some'] ?? null,
            'memory_some' => $memory['some'] ?? null,
            'memory_full' => $memory['full'] ?? null,
            'io_some' => $io['some'] ?? null,
            'io_full' => $io['full'] ?? null,
        ];

        if (array_filter($pressure, fn (?float $value): bool => $value !== null) === []) {
            return null;
        }

        return $pressure;
    }

    /**
     * The `avg60` of each line of a `/proc/pressure` file.
     *
     * @return array<'some'|'full', float>
     */
    public function parsePressure(string $contents): array
    {
        preg_match_all('/^(some|full) .*\bavg60=(\d+(?:\.\d+)?)\b/m', $contents, $matches, PREG_SET_ORDER);

        $pressure = [];

        foreach ($matches as $match) {
            $pressure[$match[1]] = (float) $match[2];
        }

        return $pressure;
    }

    /**
     * Inodes of the filesystem that holds the path. PHP has no statvfs, so
     * this asks `df`, without a shell, in the C locale so its header is not
     * translated. Null without `df` or `proc_open`, when it fails or hangs,
     * and when the filesystem keeps no inode count (btrfs reports 0).
     *
     * @return array{total: int, free: int}|null
     */
    public function inodes(string $path): ?array
    {
        $binary = $this->executable('df');

        if ($binary === null || ! function_exists('proc_open')) {
            return null;
        }

        try {
            $result = Process::timeout(self::DF_TIMEOUT_SECONDS)
                ->env(['LC_ALL' => 'C'])
                ->run([$binary, '-iP', $path]);
        } catch (Throwable) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        return $this->parseInodes($result->output());
    }

    /**
     * Reads the output of `df -iP`. The header must name the columns, since
     * a `df` that prints blocks first (macOS) would otherwise pass for one.
     *
     * @return array{total: int, free: int}|null
     */
    public function parseInodes(string $output): ?array
    {
        $lines = preg_split('/\R/', trim($output));

        if ($lines === false || count($lines) < 2) {
            return null;
        }

        $header = preg_split('/\s+/', trim($lines[0]));

        if ($header === false
            || strcasecmp($header[1] ?? '', 'Inodes') !== 0
            || ! in_array(strtolower($header[3] ?? ''), ['ifree', 'free', 'available'], true)) {
            return null;
        }

        if (preg_match('/^.*?\s+(\d+|-)\s+(\d+|-)\s+(\d+|-)\s+(\d+%|-)(?:\s|$)/', $lines[1], $match) !== 1) {
            return null;
        }

        [, $total, , $free] = $match;

        if (! ctype_digit($total) || (int) $total === 0 || ! ctype_digit($free)) {
            return null;
        }

        return ['total' => (int) $total, 'free' => (int) $free];
    }

    /**
     * The id systemd or D-Bus gave this installation of the operating
     * system. Unlike the hostname it stays when the machine is renamed, and
     * two machines with one name still differ.
     */
    public function machineId(): ?string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
            $machineId = $this->parseMachineId((string) $this->read($path));

            if ($machineId !== null) {
                return $machineId;
            }
        }

        return null;
    }

    public function parseMachineId(string $contents): ?string
    {
        $machineId = strtolower(trim($contents));

        if (preg_match('/^[0-9a-f]{32}$/', $machineId) !== 1) {
            return null;
        }

        return $machineId;
    }

    /**
     * The name the distribution gives itself, such as "Ubuntu 24.04.1 LTS".
     * Null where there is no os-release file (macOS) or it cannot be read.
     */
    public function osName(): ?string
    {
        foreach (['/etc/os-release', '/usr/lib/os-release'] as $path) {
            $osName = $this->parseOsName((string) $this->read($path));

            if ($osName !== null) {
                return $osName;
            }
        }

        return null;
    }

    /**
     * The `PRETTY_NAME` of an os-release file, whose values follow shell
     * quoting: in double or single quotes, or bare.
     */
    public function parseOsName(string $contents): ?string
    {
        if (preg_match('/^PRETTY_NAME=(.*)$/m', $contents, $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        if (preg_match('/^"(.*)"$/', $value, $quoted) === 1) {
            $value = (string) preg_replace('/\\\\(["\\\\$`])/', '$1', $quoted[1]);
        } elseif (preg_match("/^'(.*)'$/", $value, $quoted) === 1) {
            $value = $quoted[1];
        }

        return $this->limited($value);
    }

    /**
     * The release of the running kernel, such as "6.8.0-45-generic". Null
     * when `php_uname` is disabled.
     */
    public function kernel(): ?string
    {
        if (! function_exists('php_uname')) {
            return null;
        }

        return $this->limited((string) @php_uname('r'));
    }

    /** Whole seconds since the machine booted, null without `/proc/uptime`. */
    public function uptime(): ?int
    {
        $contents = $this->read('/proc/uptime');

        if ($contents === null) {
            return null;
        }

        return $this->parseUptime($contents);
    }

    /** The first number of `/proc/uptime`, floored. */
    public function parseUptime(string $contents): ?int
    {
        if (preg_match('/^\s*(\d+)(?:\.\d+)?(?:\s|$)/', $contents, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * Whether a package (a kernel, libc) asked for a reboot, as Debian and
     * Ubuntu mark it. Other distributions have no such marker, so there it
     * is null rather than false.
     */
    public function rebootRequired(): ?bool
    {
        $runDirectoryReadable = @is_dir(self::RUN_DIRECTORY) && @is_readable(self::RUN_DIRECTORY);

        return $this->parseRebootRequired(
            (string) $this->osRelease(),
            $runDirectoryReadable,
            $runDirectoryReadable && @file_exists(self::REBOOT_REQUIRED_FILE),
        );
    }

    /**
     * True when the marker exists, false when it does not but its directory
     * can be read, null when that directory cannot be read or the system is
     * not like Debian.
     */
    public function parseRebootRequired(string $osRelease, bool $runDirectoryReadable, bool $markerExists): ?bool
    {
        if (! $this->isDebianLike($osRelease) || ! $runDirectoryReadable) {
            return null;
        }

        return $markerExists;
    }

    /**
     * Whether the `ID` or `ID_LIKE` of an os-release file names Debian or
     * Ubuntu, as on Raspbian, Mint and Pop!_OS.
     */
    public function isDebianLike(string $osRelease): bool
    {
        preg_match_all('/^(ID|ID_LIKE)=(.*)$/m', $osRelease, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $ids = preg_split('/\s+/', strtolower(trim($match[2], " \t\r\"'")));

            if (array_intersect($ids === false ? [] : $ids, ['debian', 'ubuntu']) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The packages that asked for the reboot. Null when no list exists.
     *
     * @return list<string>|null
     */
    public function rebootPackages(): ?array
    {
        $contents = $this->read(self::REBOOT_PACKAGES_FILE);

        if ($contents === null) {
            return null;
        }

        return $this->parseRebootPackages($contents);
    }

    /**
     * One package per line, which apt repeats when it upgrades a package
     * twice before the reboot: unique, each cut to 100 characters, at most 50.
     *
     * @return list<string>
     */
    public function parseRebootPackages(string $contents): array
    {
        $packages = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $package = $this->limited($line);

            if ($package !== null) {
                $packages[$package] = true;
            }

            if (count($packages) === self::MAX_REBOOT_PACKAGES) {
                break;
            }
        }

        return array_map(strval(...), array_keys($packages));
    }

    /**
     * The package updates Ubuntu's update-notifier counted. Null where it
     * did not run, and when its text is in a language this cannot read.
     *
     * @return array{pending: int, security: int}|null
     */
    public function updates(): ?array
    {
        $contents = $this->read(self::UPDATES_AVAILABLE_FILE);

        if ($contents === null) {
            return null;
        }

        return $this->parseUpdates($contents);
    }

    /**
     * Reads the English text of `updates-available`, such as "14 updates can
     * be applied immediately." with "6 of these updates are standard security
     * updates.", on Ubuntu 20.04 "14 updates can be installed immediately.",
     * or before that "14 packages can be updated." with "6 updates are
     * security updates.". With Ubuntu Pro each source (ESM Infra, ESM Apps,
     * standard) has a security line of its own, so they add up; no security
     * line means none. A line about "additional" security updates counts
     * updates that cannot be installed without Ubuntu Pro, so it is neither.
     *
     * @return array{pending: int, security: int}|null
     */
    public function parseUpdates(string $contents): ?array
    {
        $pending = null;
        $security = 0;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\D*(\d+)/', $line, $number) !== 1 || str_contains($line, 'additional')) {
                continue;
            }

            if (preg_match('/can be (applied|installed|updated)/', $line) === 1) {
                $pending ??= (int) $number[1];

                continue;
            }

            if (str_contains($line, 'security update')) {
                $security += (int) $number[1];
            }
        }

        if ($pending === null) {
            return null;
        }

        return ['pending' => $pending, 'security' => $security];
    }

    /**
     * When the pending updates last changed: when update-notifier last
     * counted them, which apt has it do whenever its lists or the installed
     * packages change. Where update-notifier does not run (Debian, a minimal
     * Ubuntu), the newer of apt's lists and dpkg's status, which change on
     * the same two events. Null where none of them exists.
     */
    public function updatesStamp(): ?int
    {
        $stamp = @filemtime(self::UPDATES_AVAILABLE_FILE);

        if ($stamp !== false) {
            return $stamp;
        }

        $stamps = array_filter([@filemtime(self::APT_LISTS_DIRECTORY), @filemtime(self::DPKG_STATUS_FILE)], is_int(...));

        return $stamps === [] ? null : max($stamps);
    }

    /**
     * Whether apt has the package lists of a source to compare the installed
     * packages against. A container image usually ships without them, and
     * without lists apt finds nothing to upgrade, which would read as up to
     * date.
     */
    public function hasAptLists(): bool
    {
        $lists = @glob(self::APT_LISTS_DIRECTORY.'/*_Packages*');

        return is_array($lists) && $lists !== [];
    }

    /**
     * The packages apt can upgrade, by name, with the version on offer and
     * whether it comes from a security pocket.
     *
     * @return list<array{name: string, version: ?string, security: bool}>|null
     */
    public function upgradablePackages(): ?array
    {
        return $this->upgradable()['packages'] ?? null;
    }

    /**
     * What apt can upgrade: how many packages, how many of them from a
     * security pocket, and the packages by name. It counts the updates where
     * update-notifier does not (Debian, a minimal Ubuntu), in the same terms.
     * Asks `apt list --upgradable` without a shell, in the C locale, which
     * reads apt's lists and needs no root. Null where apt is missing or has
     * no lists, on a system that is not like Debian, and when it fails or
     * hangs.
     *
     * @return array{pending: int, security: int, packages: list<array{name: string, version: ?string, security: bool}>}|null
     */
    public function upgradable(): ?array
    {
        $binary = $this->executable('apt');

        if ($binary === null || ! function_exists('proc_open') || ! $this->isDebianLike((string) $this->osRelease()) || ! $this->hasAptLists()) {
            return null;
        }

        try {
            $result = Process::timeout(self::APT_TIMEOUT_SECONDS)
                ->env(['LC_ALL' => 'C'])
                ->run([$binary, 'list', '--upgradable']);
        } catch (Throwable) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        return $this->parseUpgradableSummary($result->output());
    }

    /**
     * The packages of `apt list --upgradable`, at most 500, as
     * `parseUpgradableSummary` reads them.
     *
     * @return list<array{name: string, version: ?string, security: bool}>
     */
    public function parseUpgradable(string $output): array
    {
        return $this->parseUpgradableSummary($output)['packages'];
    }

    /**
     * Reads `apt list --upgradable`, one package per line such as
     * `openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64
     * [upgradable from: 3.0.13-0ubuntu3.4]`. A package is a security update
     * when one of its suites ends in `-security` (`noble-security`,
     * `trixie-security`). The "Listing..." header and apt's warning about
     * its CLI are skipped. Every package counts, but at most 500 are listed.
     *
     * @return array{pending: int, security: int, packages: list<array{name: string, version: ?string, security: bool}>}
     */
    public function parseUpgradableSummary(string $output): array
    {
        $pending = 0;
        $security = 0;
        $packages = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('#^([a-z0-9][a-z0-9.+\-]*)/(\S+)\s+(\S+)#', trim($line), $match) !== 1) {
                continue;
            }

            $isSecurity = array_filter(explode(',', $match[2]), fn (string $suite): bool => str_ends_with($suite, '-security')) !== [];
            $pending++;
            $security += $isSecurity ? 1 : 0;

            if (count($packages) < self::MAX_UPDATE_PACKAGES) {
                $packages[] = [
                    'name' => mb_substr($match[1], 0, 255),
                    'version' => mb_substr($match[3], 0, 255),
                    'security' => $isSecurity,
                ];
            }
        }

        return ['pending' => $pending, 'security' => $security, 'packages' => $packages];
    }

    /**
     * The stable name of the short-lived instances this one belongs to, so
     * PingPong keeps them as one server: on ECS (Fargate or EC2) the cluster
     * and the service of the task, `ecs:prod/web`, read from the task
     * metadata endpoint ECS gives every container. Every task of a service
     * gets a new hostname and machine id; the service stays. Null on a
     * machine of its own, and when the endpoint does not answer. Asked once
     * per process.
     */
    public function fleet(): ?string
    {
        $endpoint = getenv(self::ECS_METADATA_VARIABLE);

        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        if (array_key_exists($endpoint, self::$fleets)) {
            return self::$fleets[$endpoint];
        }

        try {
            $task = Http::timeout(self::ECS_METADATA_TIMEOUT_SECONDS)
                ->connectTimeout(self::ECS_METADATA_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(rtrim($endpoint, '/').'/task')
                ->json();
        } catch (Throwable) {
            $task = null;
        }

        return self::$fleets[$endpoint] = is_array($task) ? $this->parseEcsTask($task) : null;
    }

    /**
     * Whether this runs inside an ECS task (Fargate or EC2), which sets the
     * metadata endpoint in every container. Unlike `fleet()` it asks nothing.
     */
    public function inFleet(): bool
    {
        $endpoint = getenv(self::ECS_METADATA_VARIABLE);

        return is_string($endpoint) && $endpoint !== '';
    }

    /**
     * `ecs:{cluster}/{service}` from a task metadata (v4) response: the
     * cluster's name (its ARN's last part), and the service that started the
     * task, else its task definition family (a task run by hand, or an ECS
     * agent too old to name the service).
     *
     * @param  array<array-key, mixed>  $task
     */
    public function parseEcsTask(array $task): ?string
    {
        $cluster = is_string($task['Cluster'] ?? null) ? $task['Cluster'] : '';
        $cluster = str_contains($cluster, '/') ? substr($cluster, (int) strrpos($cluster, '/') + 1) : $cluster;
        $service = is_string($task['ServiceName'] ?? null) && $task['ServiceName'] !== '' ? $task['ServiceName'] : null;
        $family = is_string($task['Family'] ?? null) && $task['Family'] !== '' ? $task['Family'] : null;
        $group = $service ?? $family;

        if ($cluster === '' || $group === null) {
            return null;
        }

        return mb_substr("ecs:{$cluster}/{$group}", 0, self::MAX_FLEET_LENGTH);
    }

    public function cores(): ?int
    {
        $contents = $this->read('/proc/cpuinfo');

        if ($contents === null) {
            return null;
        }

        return $this->parseCores($contents);
    }

    public function parseCores(string $contents): ?int
    {
        $count = preg_match_all('/^processor\s*:/m', $contents);

        if ($count === false || $count === 0) {
            return null;
        }

        return $count;
    }

    /**
     * Where an executable is on the PATH of this process, null when it is
     * not. Looked up by hand rather than with Symfony's ExecutableFinder,
     * which falls back to a shell: a server without it pays one PATH scan.
     */
    public function executable(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/')."/{$name}";

            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the pid file holds the pid of a live process. A daemon that
     * died uncleanly leaves its pid file behind, so the pid in it must still
     * be alive.
     */
    public function pidFileRuns(string $path): bool
    {
        $pid = trim((string) $this->read($path));

        if (! ctype_digit($pid) || (int) $pid === 0) {
            return false;
        }

        return $this->processRuns((int) $pid);
    }

    /**
     * Whether this process may write to the Unix socket at the path, which
     * is what connecting to it needs. Null when nothing is there.
     */
    public function socketWritable(string $path): ?bool
    {
        if (! @file_exists($path)) {
            return null;
        }

        return @is_writable($path);
    }

    /**
     * The contents of a file, null when it cannot be read.
     */
    public function contents(string $path): ?string
    {
        return $this->read($path);
    }

    /**
     * The files matching a glob pattern, sorted. Empty when none match or
     * the directory cannot be read.
     *
     * @return list<string>
     */
    public function files(string $pattern): array
    {
        $files = @glob($pattern);

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * The path with every symlink resolved, null when it does not exist.
     */
    public function realpath(string $path): ?string
    {
        $resolved = @realpath($path);

        return $resolved === false ? null : $resolved;
    }

    /** @return array{'1m': float, '5m': float, '15m': float} */
    private function loadShape(float $oneMinute, float $fiveMinutes, float $fifteenMinutes): array
    {
        return [
            '1m' => $oneMinute,
            '5m' => $fiveMinutes,
            '15m' => $fifteenMinutes,
        ];
    }

    /** Trimmed and cut to what PingPong stores, null when nothing is left. */
    private function limited(string $value): ?string
    {
        $value = trim(mb_substr(trim($value), 0, self::MAX_NAME_LENGTH));

        return $value === '' ? null : $value;
    }

    /**
     * Without `/proc` and without ext-posix nothing can tell, and then the
     * process is taken to run, as the pid file says.
     */
    private function processRuns(int $pid): bool
    {
        if (@is_dir("/proc/{$pid}")) {
            return true;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return ! @is_dir('/proc');
    }

    /** The os-release file, which systemd looks for in these two places. */
    private function osRelease(): ?string
    {
        return $this->read('/etc/os-release') ?? $this->read('/usr/lib/os-release');
    }

    /**
     * Suppressed on purpose: open_basedir and disable_functions raise
     * warnings instead of exceptions, and a warning must not reach the log
     * every minute.
     */
    private function read(string $path): ?string
    {
        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        return $contents;
    }
}
