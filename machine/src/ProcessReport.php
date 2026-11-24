<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Turns the process table into what PingPong shows when a machine is busy:
 * per Unix user how many processes it runs, the CPU they used since the
 * previous tick, the memory and swap they hold and the normalized process
 * groups they run under with per-process CPU seconds and RSS memory.
 *
 * CPU is a difference between two readings, so the counters of every process
 * are kept in the state file until the next tick. A process that started
 * since counts in full; one that started and ended between two ticks is not
 * seen at all, so a burst of short lived commands is under counted.
 *
 * Only the users that matter are sent: the busiest by CPU, the largest by
 * memory and by swap, plus any watched users and processes PingPong asked
 * for, alongside the totals so the rest still adds up.
 */
class ProcessReport
{
    public const BY_CPU = 8;

    public const BY_MEMORY = 4;

    public const BY_SWAP = 2;

    public const NAMES_PER_USER = 4;

    public const MAX_NAMES_PER_USER = 32;

    public const MAX_USERS = 128;

    /** A previous reading older than this is too old to measure a tick against. */
    public const MAX_INTERVAL_SECONDS = 900;

    /** Kernel threads have no user of their own; they are counted as this one. */
    public const KERNEL_USER = 'kernel';

    public function __construct(private Users $users) {}

    /**
     * @param  list<array{pid: int, started: int, uid: int, name: string, ticks: int, rss_bytes: int, swap_bytes: ?int, kernel: bool}>  $processes
     * @param  array{taken_at: float, uptime: float, ticks: array<string, int>}|null  $previous
     * @param  list<string>  $watchUsers
     * @param  list<array{user: string, process: string}>  $watchProcesses
     * @return array{report: array{interval_seconds: ?int, process_count: int, cpu_seconds_total: ?float, scope: string, users: list<array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}>}>}, snapshot: array{taken_at: float, uptime: float, ticks: array<string, int>}}
     */
    public function build(
        array $processes,
        ?array $previous,
        float $now,
        float $uptime,
        bool $seesEveryone,
        array $watchUsers = [],
        array $watchProcesses = [],
        ?string $currentUser = null,
    ): array {
        $interval = $previous === null ? null : $now - $previous['taken_at'];

        /* Too close, too long ago, or from before a reboot (the machine has
           been up shorter than it was then): nothing to measure against. */
        if ($previous !== null && ($interval < 1 || $interval > self::MAX_INTERVAL_SECONDS || $uptime < $previous['uptime'])) {
            $previous = null;
            $interval = null;
        }

        $hasPrevious = $previous !== null;
        $viewer = $currentUser ?? $this->users->current();

        /** @var array<string, true> $watchedUsers */
        $watchedUsers = [];

        /** @var array<string, list<string>> $watchedProcessesByUser */
        $watchedProcessesByUser = [];

        foreach ($watchUsers as $user) {
            if ($seesEveryone || $user === $viewer) {
                $watchedUsers[$user] = true;
            }
        }

        foreach ($watchProcesses as $entry) {
            $user = $entry['user'];
            $process = $entry['process'];

            if (! $seesEveryone && $user !== $viewer) {
                continue;
            }

            $watchedUsers[$user] = true;
            $watchedProcessesByUser[$user] ??= [];

            if (! in_array($process, $watchedProcessesByUser[$user], true)) {
                $watchedProcessesByUser[$user][] = $process;
            }
        }

        $snapshot = [];
        $total = 0;

        /** @var array<string, array{processes: int, ticks: int, rss_bytes: int, swap_bytes: ?int, names: array<string, array{count: int, ticks: int, rss_bytes: int}>}> $groups */
        $groups = [];

        foreach ($processes as $process) {
            $key = $process['pid'].':'.$process['started'];
            $snapshot[$key] = $process['ticks'];
            $user = $process['kernel'] ? self::KERNEL_USER : $this->users->name($process['uid']);
            $group = $groups[$user] ?? ['processes' => 0, 'ticks' => 0, 'rss_bytes' => 0, 'swap_bytes' => null, 'names' => []];
            $name = $process['name'];
            $nameGroup = $group['names'][$name] ?? ['count' => 0, 'ticks' => 0, 'rss_bytes' => 0];

            $group['processes']++;
            $group['rss_bytes'] += $process['rss_bytes'];
            $nameGroup['count']++;
            $nameGroup['rss_bytes'] += $process['rss_bytes'];

            if ($process['swap_bytes'] !== null) {
                $group['swap_bytes'] = ($group['swap_bytes'] ?? 0) + $process['swap_bytes'];
            }

            if ($previous !== null) {
                $used = $this->ticksSince($process, $key, $previous);
                $group['ticks'] += $used;
                $nameGroup['ticks'] += $used;
                $total += $used;
            }

            $group['names'][$name] = $nameGroup;
            $groups[$user] = $group;
        }

        /** @var array<string, array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}>}> $users */
        $users = [];

        foreach ($groups as $user => $group) {
            $userKey = (string) $user;
            $users[$userKey] = [
                'user' => $userKey,
                'processes' => $group['processes'],
                'cpu_seconds' => $hasPrevious ? round($group['ticks'] / ProcessTable::TICKS_PER_SECOND, 2) : null,
                'rss_bytes' => $group['rss_bytes'],
                'swap_bytes' => $group['swap_bytes'],
                'names' => $this->selectNames($group['names'], $watchedProcessesByUser[$userKey] ?? [], $hasPrevious),
            ];
        }

        foreach (array_keys($watchedUsers) as $watchedUser) {
            $userKey = (string) $watchedUser;

            if (isset($users[$userKey])) {
                continue;
            }

            $users[$userKey] = [
                'user' => $userKey,
                'processes' => 0,
                'cpu_seconds' => $hasPrevious ? 0.0 : null,
                'rss_bytes' => 0,
                'swap_bytes' => 0,
                'names' => $this->selectNames([], $watchedProcessesByUser[$userKey] ?? [], $hasPrevious),
            ];
        }

        return [
            'report' => [
                'interval_seconds' => $interval === null ? null : (int) round($interval),
                'process_count' => count($processes),
                'cpu_seconds_total' => $hasPrevious ? round($total / ProcessTable::TICKS_PER_SECOND, 2) : null,
                'scope' => $seesEveryone ? 'machine' : 'user',
                'users' => $this->select($users, $watchedUsers),
            ],
            'snapshot' => ['taken_at' => $now, 'uptime' => $uptime, 'ticks' => $snapshot],
        ];
    }

    /**
     * @param  array{pid: int, started: int, ticks: int}  $process
     * @param  array{taken_at: float, uptime: float, ticks: array<string, int>}  $previous
     */
    private function ticksSince(array $process, string $key, array $previous): int
    {
        if (isset($previous['ticks'][$key])) {
            return max(0, $process['ticks'] - $previous['ticks'][$key]);
        }

        /* Started after the previous reading: everything it used is new. A
           process that was there but not read then (it was hidden for a
           moment) is left out rather than counted from its start. */
        $startedAt = $process['started'] / ProcessTable::TICKS_PER_SECOND;

        return $startedAt >= $previous['uptime'] - 1 ? $process['ticks'] : 0;
    }

    /**
     * @param  array<string, array{count: int, ticks: int, rss_bytes: int}>  $names
     * @param  list<string>  $watchedProcesses
     * @return list<array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}>
     */
    private function selectNames(array $names, array $watchedProcesses, bool $hasPrevious): array
    {
        /** @var array<string, array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}> $discovered */
        $discovered = [];

        foreach ($names as $name => $stats) {
            $nameKey = (string) $name;
            $discovered[$nameKey] = [
                'name' => $nameKey,
                'count' => $stats['count'],
                'cpu_seconds' => $hasPrevious ? round($stats['ticks'] / ProcessTable::TICKS_PER_SECOND, 2) : null,
                'rss_bytes' => $stats['rss_bytes'],
            ];
        }

        $ranked = array_values($discovered);
        usort($ranked, fn (array $a, array $b): int => $this->compareNames($a, $b));

        /** @var array<string, array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}> $chosen */
        $chosen = [];

        foreach ($watchedProcesses as $watchedName) {
            if (count($chosen) >= self::MAX_NAMES_PER_USER) {
                break;
            }

            $matched = false;

            foreach ($ranked as $entry) {
                if (! $this->matchesWatchedProcess($entry['name'], $watchedName)) {
                    continue;
                }

                $matched = true;

                if (isset($chosen[$entry['name']])) {
                    continue;
                }

                if (count($chosen) >= self::MAX_NAMES_PER_USER) {
                    break;
                }

                $chosen[$entry['name']] = $entry;
            }

            if (! $matched && ! isset($chosen[$watchedName]) && count($chosen) < self::MAX_NAMES_PER_USER) {
                $chosen[$watchedName] = [
                    'name' => $watchedName,
                    'count' => 0,
                    'cpu_seconds' => $hasPrevious ? 0.0 : null,
                    'rss_bytes' => 0,
                ];
            }
        }

        foreach (array_slice($ranked, 0, self::NAMES_PER_USER) as $entry) {
            if (isset($chosen[$entry['name']])) {
                continue;
            }

            if (count($chosen) >= self::MAX_NAMES_PER_USER) {
                break;
            }

            $chosen[$entry['name']] = $entry;
        }

        $selected = array_values($chosen);
        usort($selected, fn (array $a, array $b): int => $this->compareNames($a, $b));

        return $selected;
    }

    private function matchesWatchedProcess(string $discoveredName, string $watchedName): bool
    {
        if ($discoveredName === $watchedName) {
            return true;
        }

        return $watchedName === 'php-fpm' && str_starts_with($discoveredName, 'php-fpm: ');
    }

    /**
     * @param  array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}  $a
     * @param  array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}  $b
     */
    private function compareNames(array $a, array $b): int
    {
        return ($b['cpu_seconds'] ?? 0.0) <=> ($a['cpu_seconds'] ?? 0.0)
            ?: $b['rss_bytes'] <=> $a['rss_bytes']
            ?: $b['count'] <=> $a['count']
            ?: strcmp($a['name'], $b['name']);
    }

    /**
     * @param  array<string, array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}>}>  $users
     * @param  array<string, true>  $watchedUsers
     * @return list<array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}>}>
     */
    private function select(array $users, array $watchedUsers): array
    {
        /** @var array<string, array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int, cpu_seconds: ?float, rss_bytes: int}>}> $chosen */
        $chosen = [];
        $list = array_values($users);

        foreach ([
            'cpu_seconds' => self::BY_CPU,
            'rss_bytes' => self::BY_MEMORY,
            'swap_bytes' => self::BY_SWAP,
        ] as $field => $count) {
            usort($list, fn (array $a, array $b): int => $this->measure($b, $field) <=> $this->measure($a, $field) ?: strcmp($a['user'], $b['user']));

            foreach (array_slice($list, 0, $count) as $user) {
                if ($this->measure($user, $field) > 0) {
                    $chosen[$user['user']] = $user;
                }
            }
        }

        foreach (array_keys($watchedUsers) as $watchedUser) {
            $userKey = (string) $watchedUser;

            if (isset($chosen[$userKey]) || ! isset($users[$userKey])) {
                continue;
            }

            if (count($chosen) >= self::MAX_USERS) {
                break;
            }

            $chosen[$userKey] = $users[$userKey];
        }

        $selected = array_values($chosen);

        usort($selected, fn (array $a, array $b): int => ($b['cpu_seconds'] ?? 0.0) <=> ($a['cpu_seconds'] ?? 0.0)
            ?: $b['rss_bytes'] <=> $a['rss_bytes']
            ?: $b['processes'] <=> $a['processes']
            ?: strcmp($a['user'], $b['user']));

        return $selected;
    }

    /**
     * @param  array{cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int}  $user
     * @param  'cpu_seconds'|'rss_bytes'|'swap_bytes'  $field
     */
    private function measure(array $user, string $field): float
    {
        return (float) ($user[$field] ?? 0);
    }
}
