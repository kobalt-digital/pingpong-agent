<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Reads every process from `/proc`: who runs it, how much CPU it has used
 * since it started and how much memory it holds. Never the command line,
 * which can carry passwords and keys; only the name the kernel keeps for it,
 * and for php-fpm the name of its pool, which is all that tells the sites of
 * one server apart.
 *
 * About 45 microseconds a process: one `stat` file per process and the owner
 * of its directory, plus its `status` file only while the machine swaps.
 */
class ProcessTable
{
    /** The kernel counts CPU time in ticks of 1/100 second on every Linux PingPong meets. */
    public const TICKS_PER_SECOND = 100;

    /** The memory page Linux uses on x86 and most ARM machines. */
    public const PAGE_BYTES = 4096;

    /** Set in the flags of a kernel thread (PF_KTHREAD). */
    private const KERNEL_THREAD_FLAG = 0x00200000;

    public function __construct(private string $proc = '/proc') {}

    /**
     * @return list<array{pid: int, started: int, uid: int, name: string, ticks: int, rss_bytes: int, swap_bytes: ?int, kernel: bool}>|null
     */
    public function read(bool $withSwap): ?array
    {
        $entries = @scandir($this->proc);

        if ($entries === false) {
            return null;
        }

        $processes = [];

        foreach ($entries as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $directory = "{$this->proc}/{$entry}";
            $stat = @file_get_contents("{$directory}/stat");
            $uid = @fileowner($directory);

            if ($stat === false || $uid === false) {
                continue;
            }

            $process = $this->parseStat($stat);

            if ($process === null) {
                continue;
            }

            $name = $process['name'];

            if (str_starts_with($name, 'php-fpm')) {
                $name = $this->fpmName((string) @file_get_contents("{$directory}/cmdline")) ?? $name;
            }

            $swap = null;

            if ($withSwap && ! $process['kernel']) {
                $swap = $this->parseSwap((string) @file_get_contents("{$directory}/status"));
            }

            $processes[] = [
                'pid' => (int) $entry,
                'started' => $process['started'],
                'uid' => $uid,
                'name' => $name,
                'ticks' => $process['ticks'],
                'rss_bytes' => $process['rss_pages'] * self::PAGE_BYTES,
                'swap_bytes' => $swap,
                'kernel' => $process['kernel'],
            ];
        }

        return $processes;
    }

    /**
     * One line of `/proc/<pid>/stat`. The name sits in parentheses and may
     * hold spaces and parentheses itself, so the fields are counted from the
     * last closing one.
     *
     * @return array{name: string, ticks: int, rss_pages: int, started: int, kernel: bool}|null
     */
    public function parseStat(string $stat): ?array
    {
        $open = strpos($stat, '(');
        $close = strrpos($stat, ')');

        if ($open === false || $close === false || $close < $open) {
            return null;
        }

        $fields = explode(' ', trim(substr($stat, $close + 2)));

        /* Field N of proc(5) is index N - 3 here: flags (9), utime (14),
           stime (15), starttime (22) and rss (24). */
        if (count($fields) < 22) {
            return null;
        }

        return [
            'name' => substr($stat, $open + 1, $close - $open - 1),
            'ticks' => (int) $fields[11] + (int) $fields[12],
            'rss_pages' => max(0, (int) $fields[21]),
            'started' => (int) $fields[19],
            'kernel' => ((int) $fields[6] & self::KERNEL_THREAD_FLAG) !== 0,
        ];
    }

    /**
     * "php-fpm: pool desprekern" names its pool, "php-fpm: master process
     * (/etc/php/8.3/fpm/php-fpm.conf)" is the master. Nothing else of the
     * command line is kept.
     */
    public function fpmName(string $cmdline): ?string
    {
        $cmdline = str_replace("\0", ' ', $cmdline);

        if (preg_match('/^php-fpm[^:]*: pool (\S+)/', $cmdline, $match) === 1) {
            return 'php-fpm: '.$match[1];
        }

        if (preg_match('/^php-fpm[^:]*: master/', $cmdline) === 1) {
            return 'php-fpm: master';
        }

        return null;
    }

    public function parseSwap(string $status): ?int
    {
        if (preg_match('/^VmSwap:\s+(\d+)\s+kB/m', $status, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 1024;
    }

    /**
     * Seconds since boot, from `/proc/uptime`.
     */
    public function uptime(): ?float
    {
        $contents = @file_get_contents("{$this->proc}/uptime");

        if ($contents === false || preg_match('/^(\d+(?:\.\d+)?)/', $contents, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }

    /**
     * Whether this process sees the processes of other users: `/proc` mounted
     * with `hidepid` (common on shared hosting) hides every process but your
     * own, and then a list of users would only ever name you.
     */
    public function seesEveryone(): bool
    {
        return @is_readable("{$this->proc}/1/stat");
    }
}
