<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Turns the process table into what PingPong shows when a machine is busy:
 * per Unix user how many processes it runs, the CPU they used since the
 * previous tick, the memory and swap they hold and the names they run under.
 *
 * CPU is a difference between two readings, so the counters of every process
 * are kept in the state file until the next tick. A process that started
 * since counts in full; one that started and ended between two ticks is not
 * seen at all, so a burst of short lived commands is under counted.
 *
 * Only the users that matter are sent: the busiest by CPU, the largest by
 * memory and by swap, plus the totals, so the rest still adds up.
 */
class ProcessReport
{
    public const BY_CPU = 8;

    public const BY_MEMORY = 4;

    public const BY_SWAP = 2;

    public const NAMES_PER_USER = 4;

    /** A previous reading older than this is too old to measure a tick against. */
    public const MAX_INTERVAL_SECONDS = 900;

    /** Kernel threads have no user of their own; they are counted as this one. */
    public const KERNEL_USER = 'kernel';

    public function __construct(private Users $users) {}

    /**
     * @param  list<array{pid: int, started: int, uid: int, name: string, ticks: int, rss_bytes: int, swap_bytes: ?int, kernel: bool}>  $processes
     * @param  array{taken_at: float, uptime: float, ticks: array<string, int>}|null  $previous
     * @return array{report: array{interval_seconds: ?int, process_count: int, cpu_seconds_total: ?float, scope: string, users: list<array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int}>}>}, snapshot: array{taken_at: float, uptime: float, ticks: array<string, int>}}
     */
    public function build(array $processes, ?array $previous, float $now, float $uptime, bool $seesEveryone): array
    {
        $interval = $previous === null ? null : $now - $previous['taken_at'];

        /* Too close, too long ago, or from before a reboot (the machine has
           been up shorter than it was then): nothing to measure against. */
        if ($previous !== null && ($interval < 1 || $interval > self::MAX_INTERVAL_SECONDS || $uptime < $previous['uptime'])) {
            $previous = null;
            $interval = null;
        }

        $snapshot = [];
        $total = 0;

        /** @var array<string, array{processes: int, ticks: int, rss_bytes: int, swap_bytes: ?int, names: array<string, int>}> $groups */
        $groups = [];

        foreach ($processes as $process) {
            $key = $process['pid'].':'.$process['started'];
            $snapshot[$key] = $process['ticks'];
            $user = $process['kernel'] ? self::KERNEL_USER : $this->users->name($process['uid']);
            $group = $groups[$user] ?? ['processes' => 0, 'ticks' => 0, 'rss_bytes' => 0, 'swap_bytes' => null, 'names' => []];

            $group['processes']++;
            $group['rss_bytes'] += $process['rss_bytes'];
            $group['names'][$process['name']] = ($group['names'][$process['name']] ?? 0) + 1;

            if ($process['swap_bytes'] !== null) {
                $group['swap_bytes'] = ($group['swap_bytes'] ?? 0) + $process['swap_bytes'];
            }

            if ($previous !== null) {
                $used = $this->ticksSince($process, $key, $previous);
                $group['ticks'] += $used;
                $total += $used;
            }

            $groups[$user] = $group;
        }

        $users = [];

        foreach ($groups as $user => $group) {
            $users[] = [
                'user' => (string) $user,
                'processes' => $group['processes'],
                'cpu_seconds' => $previous === null ? null : round($group['ticks'] / ProcessTable::TICKS_PER_SECOND, 2),
                'rss_bytes' => $group['rss_bytes'],
                'swap_bytes' => $group['swap_bytes'],
                'names' => $this->topNames($group['names']),
            ];
        }

        return [
            'report' => [
                'interval_seconds' => $interval === null ? null : (int) round($interval),
                'process_count' => count($processes),
                'cpu_seconds_total' => $previous === null ? null : round($total / ProcessTable::TICKS_PER_SECOND, 2),
                'scope' => $seesEveryone ? 'machine' : 'user',
                'users' => $this->select($users),
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
     * @param  array<string, int>  $names
     * @return list<array{name: string, count: int}>
     */
    private function topNames(array $names): array
    {
        arsort($names);

        $top = [];

        foreach (array_slice($names, 0, self::NAMES_PER_USER, true) as $name => $count) {
            $top[] = ['name' => (string) $name, 'count' => $count];
        }

        return $top;
    }

    /**
     * @param  list<array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int}>}>  $users
     * @return list<array{user: string, processes: int, cpu_seconds: ?float, rss_bytes: int, swap_bytes: ?int, names: list<array{name: string, count: int}>}>
     */
    private function select(array $users): array
    {
        $chosen = [];

        foreach ([
            'cpu_seconds' => self::BY_CPU,
            'rss_bytes' => self::BY_MEMORY,
            'swap_bytes' => self::BY_SWAP,
        ] as $field => $count) {
            usort($users, fn (array $a, array $b): int => $this->measure($b, $field) <=> $this->measure($a, $field) ?: strcmp($a['user'], $b['user']));

            foreach (array_slice($users, 0, $count) as $user) {
                if ($this->measure($user, $field) > 0) {
                    $chosen[$user['user']] = $user;
                }
            }
        }

        usort($chosen, fn (array $a, array $b): int => ($b['cpu_seconds'] ?? 0) <=> ($a['cpu_seconds'] ?? 0) ?: $b['rss_bytes'] <=> $a['rss_bytes']);

        return $chosen;
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
