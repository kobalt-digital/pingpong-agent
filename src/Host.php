<?php

namespace KobaltDigital\PingPong;

/**
 * Reads operating system facts that only some platforms offer. Every method
 * answers null when this one does not, so callers never have to know whether
 * they run on Linux, macOS or a locked down shared host.
 */
class Host
{
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

    /** @return array{'1m': float, '5m': float, '15m': float} */
    private function loadShape(float $oneMinute, float $fiveMinutes, float $fifteenMinutes): array
    {
        return [
            '1m' => $oneMinute,
            '5m' => $fiveMinutes,
            '15m' => $fifteenMinutes,
        ];
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
