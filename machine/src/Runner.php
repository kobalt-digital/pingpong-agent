<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Runs a program without a shell, in the C locale, and gives up on it after a
 * timeout. Output is read line by line, so a program that prints a lot (`du`
 * over a whole home directory) is filtered as it goes instead of held whole.
 */
class Runner
{
    /**
     * @param  list<string>  $command
     * @param  (callable(string): void)|null  $onLine  gets every line of stdout, without its newline
     * @return array{exit: int, output: string, timed_out: bool}|null null when it could not start
     */
    public function run(array $command, int $timeoutSeconds, ?callable $onLine = null, string $separator = "\n"): ?array
    {
        if (! function_exists('proc_open')) {
            return null;
        }

        $environment = ['LC_ALL' => 'C', 'PATH' => (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')];

        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $environment);

        if (! is_resource($process)) {
            return null;
        }

        $stdout = $pipes[1];
        stream_set_blocking($stdout, false);

        $deadline = microtime(true) + $timeoutSeconds;
        $output = '';
        $pending = '';
        $timedOut = false;

        while (true) {
            $read = [$stdout];
            $write = null;
            $except = null;
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                $timedOut = true;
                break;
            }

            $ready = @stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1_000_000));

            if ($ready === false) {
                break;
            }

            if ($ready === 0) {
                continue;
            }

            $chunk = fread($stdout, 65536);

            if ($chunk === false || ($chunk === '' && feof($stdout))) {
                break;
            }

            if ($onLine === null) {
                $output .= $chunk;

                continue;
            }

            $pending .= $chunk;

            while (($newline = strpos($pending, $separator)) !== false) {
                $onLine(substr($pending, 0, $newline));
                $pending = substr($pending, $newline + 1);
            }
        }

        if ($onLine !== null && $pending !== '' && ! $timedOut) {
            $onLine($pending);
        }

        fclose($stdout);

        if ($timedOut) {
            @proc_terminate($process, 9);

            /* A program stuck on a filesystem that stopped answering (NFS)
               does not die even of a kill until the kernel lets go of it;
               waiting for it would stall this run too, so it is left. */
            if (! $this->ends($process)) {
                return ['exit' => -1, 'output' => $output, 'timed_out' => true];
            }
        }

        $exit = proc_close($process);

        return ['exit' => $exit, 'output' => $output, 'timed_out' => $timedOut];
    }

    /**
     * Whether a process that was killed is gone within a second.
     *
     * @param  resource  $process
     */
    private function ends($process): bool
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $status = @proc_get_status($process);

            if (! $status['running']) {
                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    /**
     * Where a program is on the PATH, null when it is not.
     */
    public function find(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin')) as $directory) {
            $candidate = rtrim($directory, '/')."/{$name}";

            if ($directory !== '' && @is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
