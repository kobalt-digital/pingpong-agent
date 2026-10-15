<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Host;

/**
 * Every process PM2 runs for the scheduler's user. Left out when PM2 is not
 * on the PATH. No PM2 command runs while no daemon does, since every one of
 * them would start one; no daemon means no process runs, so the list is empty.
 * Only a handful of facts per process leave the server: `pm2 jlist` also
 * carries the environment of every process, secrets included.
 */
class Pm2 implements Collector
{
    use CollectsFacts;

    public const MAX_PROCESSES = 100;

    public const TIMEOUT_SECONDS = 3;

    public const MAX_NAME_LENGTH = 255;

    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'pm2';
    }

    /** @return array{processes: list<array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}>|null, error: ?string}|null */
    public function collect(): ?array
    {
        $binary = $this->host->executable('pm2');

        if ($binary === null) {
            return null;
        }

        $home = $this->home();

        if ($home === null) {
            return $this->failed('Neither PM2_HOME nor HOME is set, so the PM2 daemon cannot be found');
        }

        if (! $this->host->pidFileRuns("{$home}/pm2.pid")) {
            return ['processes' => [], 'error' => null];
        }

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([$binary, 'jlist']);
        } catch (ProcessTimedOutException) {
            return $this->failed('pm2 jlist did not answer within '.self::TIMEOUT_SECONDS.' seconds');
        }

        if ($result->failed()) {
            return $this->failed(trim($result->errorOutput()) ?: "pm2 jlist exited with code {$result->exitCode()}");
        }

        $list = $this->decode($result->output());

        if ($list === null) {
            return $this->failed('pm2 jlist printed no JSON list');
        }

        return ['processes' => $this->processes($list), 'error' => null];
    }

    /**
     * PM2 prints notices such as "In-memory PM2 is out-of-date" or
     * "[PM2][WARN] ..." before the JSON, so the list starts at the first line
     * from which the rest decodes as one.
     *
     * @return list<mixed>|null
     */
    private function decode(string $output): ?array
    {
        $offset = 0;

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, '[')) {
                $list = json_decode(substr($output, $offset), true);

                if (is_array($list) && array_is_list($list)) {
                    return $list;
                }
            }

            $offset += strlen($line) + 1;
        }

        return null;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}>
     */
    private function processes(array $list): array
    {
        $now = Date::now()->getTimestampMs();

        $processes = array_values(array_filter(array_map(fn (mixed $entry): ?array => $this->process($entry, $now), $list)));

        usort($processes, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($processes, 0, self::MAX_PROCESSES);
    }

    /**
     * An entry without an id, a name or a status is not a process PingPong
     * can judge, so it is left out.
     *
     * @return array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}|null
     */
    private function process(mixed $entry, int $now): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $env = is_array($entry['pm2_env'] ?? null) ? $entry['pm2_env'] : [];
        $id = $entry['pm_id'] ?? null;
        $name = $entry['name'] ?? null;
        $status = $env['status'] ?? null;

        if (! is_int($id) || ! is_string($name) || ! is_string($status)) {
            return null;
        }

        $restarts = $env['restart_time'] ?? null;
        $startedAt = $env['pm_uptime'] ?? null;
        $memory = $entry['monit']['memory'] ?? null;

        return [
            'id' => $id,
            'name' => mb_substr($name, 0, self::MAX_NAME_LENGTH),
            'status' => $status,
            'restarts' => is_int($restarts) ? $restarts : 0,
            'uptime_seconds' => $status === 'online' && is_numeric($startedAt)
                ? max(0, intdiv($now - (int) $startedAt, 1000))
                : null,
            'memory_bytes' => is_int($memory) ? $memory : null,
        ];
    }

    /**
     * Where the PM2 daemon keeps its files, resolved the way PM2 does.
     */
    private function home(): ?string
    {
        $pm2Home = getenv('PM2_HOME');

        if (is_string($pm2Home) && $pm2Home !== '') {
            return rtrim($pm2Home, '/');
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/').'/.pm2';
        }

        return null;
    }

    /** @return array{processes: null, error: string} */
    private function failed(string $message): array
    {
        return ['processes' => null, 'error' => $this->error($message)];
    }
}
