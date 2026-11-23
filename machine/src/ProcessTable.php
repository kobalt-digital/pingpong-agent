<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Reads every process from `/proc`: who runs it, how much CPU it has used
 * since it started and how much memory it holds. Command lines are only
 * inspected locally to derive a stable group name (`php-fpm`, `php artisan
 * <subcommand>`, `node <script>`, `python <script>`); flags, inline code and
 * secrets never leave the machine.
 *
 * One `stat` file per process, its `cmdline` for non-kernel processes and the
 * owner of its directory, plus its `status` file only while the machine swaps.
 */
class ProcessTable
{
    /** The kernel counts CPU time in ticks of 1/100 second on every Linux PingPong meets. */
    public const TICKS_PER_SECOND = 100;

    /** The memory page Linux uses on x86 and most ARM machines. */
    public const PAGE_BYTES = 4096;

    /** Maximum length of a normalized process name PingPong accepts. */
    public const MAX_NAME_LENGTH = 64;

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

            $cmdline = $process['kernel'] ? '' : (string) @file_get_contents("{$directory}/cmdline");
            $name = $this->normalizeName($process['name'], $cmdline);

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
     * Normalizes a process's `comm` and `cmdline` to a stable group name
     * without `/` or control characters, capped at 64 characters.
     */
    public function normalizeName(string $comm, string $cmdline = ''): string
    {
        $cleanComm = self::sanitizeName($comm);
        $trimmedCmdline = ltrim(str_replace("\0", ' ', $cmdline));

        if (str_starts_with($cleanComm, 'php-fpm') || str_starts_with($trimmedCmdline, 'php-fpm')) {
            return $this->fpmName($trimmedCmdline);
        }

        $tokens = $this->cmdlineTokens($cmdline);
        $binary = $tokens === [] ? '' : basename($tokens[0]);

        if (str_starts_with($binary, 'php-fpm')) {
            return $this->fpmName($trimmedCmdline);
        }

        if (preg_match('/^php(\d+(\.\d+)?)?$/', $binary) === 1) {
            $artisan = $this->artisanName($tokens);

            if ($artisan !== null) {
                return $artisan;
            }
        }

        if ($binary === 'node') {
            $script = $this->scriptArgument(
                $tokens,
                ['-e', '--eval', '-p', '--print'],
                ['-r', '--require', '--import', '--loader', '--env-file', '--conditions', '-C', '--title'],
            );

            return $script === null ? 'node' : self::sanitizeName("node {$script}");
        }

        if (preg_match('/^python(\d+(\.\d+)?)?$/', $binary) === 1) {
            $script = $this->scriptArgument(
                $tokens,
                ['-c', '-m', '--command'],
                ['-W', '-X', '--check-hash-based-pycs'],
            );

            return $script === null
                ? ($cleanComm !== '' ? $cleanComm : 'python')
                : self::sanitizeName("python {$script}");
        }

        return $cleanComm !== '' ? $cleanComm : 'unknown';
    }

    /**
     * Preserves `php-fpm: {pool}` so PingPong can suggest which Monitor a Unix
     * user belongs to, and `php-fpm: master` for the master process. Nothing
     * else of the command line is kept.
     */
    private function fpmName(string $trimmedCmdline): string
    {
        if (preg_match('/^php-fpm[^:]*: pool (\S+)/', $trimmedCmdline, $match) === 1) {
            $pool = self::sanitizeName($match[1]);

            if ($pool !== '') {
                return self::sanitizeName("php-fpm: {$pool}");
            }
        }

        if (preg_match('/^php-fpm[^:]*: master/', $trimmedCmdline) === 1) {
            return 'php-fpm: master';
        }

        return 'php-fpm';
    }

    /**
     * Strips control characters, replaces `/` with `-`, trims whitespace, and
     * caps the result at `MAX_NAME_LENGTH` characters.
     */
    public static function sanitizeName(string $name): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $clean = str_replace('/', '-', $clean);
        $clean = trim($clean);

        if ($clean === '') {
            return '';
        }

        $capped = function_exists('mb_substr')
            ? mb_substr($clean, 0, self::MAX_NAME_LENGTH, 'UTF-8')
            : substr($clean, 0, self::MAX_NAME_LENGTH);

        return rtrim($capped);
    }

    /**
     * @return list<string>
     */
    private function cmdlineTokens(string $cmdline): array
    {
        if ($cmdline === '') {
            return [];
        }

        if (str_contains($cmdline, "\0")) {
            return array_values(array_filter(
                explode("\0", $cmdline),
                fn (string $token): bool => $token !== '',
            ));
        }

        $trimmed = trim($cmdline);

        if ($trimmed === '') {
            return [];
        }

        return preg_split('/\s+/', $trimmed) ?: [];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function artisanName(array $tokens): ?string
    {
        $count = count($tokens);

        for ($i = 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (in_array($token, ['-r', '-B', '-R', '-F', '-E'], true)) {
                return null;
            }

            if (in_array($token, ['-d', '-c', '-z'], true)) {
                $i++;

                continue;
            }

            if ($token === '-f' || str_starts_with($token, '-')) {
                continue;
            }

            if ($token === 'artisan' || str_ends_with($token, '/artisan')) {
                $subcommand = $tokens[$i + 1] ?? null;

                if ($subcommand !== null && $subcommand !== '' && ! str_starts_with($subcommand, '-')) {
                    $normalized = self::sanitizeName("php artisan {$subcommand}");

                    return $normalized === '' ? null : $normalized;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $inlineFlags
     * @param  list<string>  $valueFlags
     */
    private function scriptArgument(array $tokens, array $inlineFlags, array $valueFlags): ?string
    {
        $count = count($tokens);
        $endOfFlags = false;

        for ($i = 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! $endOfFlags) {
                if ($token === '--') {
                    $endOfFlags = true;

                    continue;
                }

                if (in_array($token, $valueFlags, true)) {
                    $i++;

                    continue;
                }

                if ($this->isAttachedShortValueFlag($token, $valueFlags)) {
                    continue;
                }

                if ($this->isInlineCodeFlag($token, $inlineFlags)) {
                    return null;
                }

                if (str_starts_with($token, '-')) {
                    continue;
                }
            }

            $base = basename($token);

            if ($base === '' || $base === '.' || $base === '..') {
                return null;
            }

            return $base;
        }

        return null;
    }

    /**
     * @param  list<string>  $valueFlags
     */
    private function isAttachedShortValueFlag(string $token, array $valueFlags): bool
    {
        if (! str_starts_with($token, '-') || str_starts_with($token, '--') || strlen($token) <= 2) {
            return false;
        }

        return in_array(substr($token, 0, 2), $valueFlags, true);
    }

    /**
     * @param  list<string>  $inlineFlags
     */
    private function isInlineCodeFlag(string $token, array $inlineFlags): bool
    {
        if (! str_starts_with($token, '-') || $token === '-') {
            return false;
        }

        if (str_starts_with($token, '--')) {
            foreach ($inlineFlags as $flag) {
                if (str_starts_with($flag, '--') && ($token === $flag || str_starts_with($token, $flag.'='))) {
                    return true;
                }
            }

            return false;
        }

        $bundle = substr($token, 1);

        foreach ($inlineFlags as $flag) {
            if (! str_starts_with($flag, '--') && strlen($flag) === 2 && str_contains($bundle, $flag[1])) {
                return true;
            }
        }

        return false;
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
