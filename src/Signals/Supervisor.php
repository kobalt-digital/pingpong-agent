<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Host;

/**
 * The processes Supervisor runs for this app. Supervisor lists the daemons
 * of every site on the server, so its program config tells which run from
 * the app's directory; the others are only counted. Left out when
 * `supervisorctl` is not on the PATH.
 */
class Supervisor implements Collector
{
    use CollectsFacts;

    public const MAX_PROCESSES = 100;

    public const TIMEOUT_SECONDS = 3;

    public const MAX_LENGTH = 255;

    public const SOCKET = '/var/run/supervisor.sock';

    public const MAIN_CONFIG = '/etc/supervisor/supervisord.conf';

    public const INCLUDED_CONFIGS = '/etc/supervisor/conf.d/*.conf';

    private const PROCESS_LINE = '/^(\S+)\s+(STOPPED|STARTING|RUNNING|BACKOFF|STOPPING|EXITED|FATAL|UNKNOWN)\b(.*)$/';

    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'supervisor';
    }

    /**
     * A socket the scheduler's user may not open fails every tick the same
     * way, so it is told without spawning `supervisorctl`, which is a
     * Python start.
     *
     * @return array{processes: list<array{name: string, status: string, uptime_seconds: ?int, own: ?bool, command: ?string}>|null, others: ?int, error: ?string}|null
     */
    public function collect(): ?array
    {
        $binary = $this->host->executable('supervisorctl');

        if ($binary === null) {
            return null;
        }

        if ($this->host->socketWritable(self::SOCKET) === false) {
            return $this->failed('Permission denied on '.self::SOCKET);
        }

        try {
            $result = Process::timeout(self::TIMEOUT_SECONDS)->run([$binary, 'status']);
        } catch (ProcessTimedOutException) {
            return $this->failed('supervisorctl status did not answer within '.self::TIMEOUT_SECONDS.' seconds');
        }

        // Supervisor 4 exits non zero whenever a process is not running, so
        // only the output tells a list from a failure.
        $lines = $this->processLines($result->output());

        if ($lines === []) {
            $error = $this->firstLine($result->output()) ?? $this->firstLine($result->errorOutput());

            return $error === null && $result->successful()
                ? ['processes' => [], 'others' => 0, 'error' => null]
                : $this->failed($error ?? 'supervisorctl status exited without reporting processes');
        }

        return $this->processes($lines);
    }

    /** @return list<array{name: string, status: string, description: string}> */
    private function processLines(string $output): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match(self::PROCESS_LINE, trim($line), $match) === 1) {
                $lines[] = ['name' => $match[1], 'status' => $match[2], 'description' => $match[3]];
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{name: string, status: string, description: string}>  $lines
     * @return array{processes: list<array{name: string, status: string, uptime_seconds: ?int, own: ?bool, command: ?string}>, others: int, error: null}
     */
    private function processes(array $lines): array
    {
        $config = $this->config();
        $roots = $this->roots();

        $processes = [];
        $others = 0;

        foreach ($lines as $line) {
            $program = $config === null ? null : $this->programOf($line['name'], $config);
            $own = $program === null ? null : $this->owns($program, $roots);

            if ($own === false) {
                $others++;

                continue;
            }

            $processes[] = [
                'name' => mb_substr($line['name'], 0, self::MAX_LENGTH),
                'status' => $line['status'],
                'uptime_seconds' => $line['status'] === 'RUNNING' ? $this->uptime($line['description']) : null,
                'own' => $own,
                'command' => $own === true ? $this->command($program['command'] ?? null) : null,
            ];
        }

        return [
            'processes' => array_slice($processes, 0, self::MAX_PROCESSES),
            'others' => $others,
            'error' => null,
        ];
    }

    /**
     * `uptime 1 day, 2:03:04`, `uptime 3 days, 2:03:04` or `uptime 2:03:04`.
     */
    private function uptime(string $description): ?int
    {
        if (preg_match('/uptime (?:(\d+) days?, )?(\d+):(\d{2}):(\d{2})/', $description, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 86400 + (int) $match[2] * 3600 + (int) $match[3] * 60 + (int) $match[4];
    }

    /**
     * Supervisor prints `group:process` or `process`. A program with
     * several processes is a group of its own name; a `[group:x]` holds
     * programs whose processes are named after their program, with a
     * `_NN` suffix when it runs several.
     *
     * @param  array{programs: array<string, array{directory?: string, command?: string}>, groups: array<string, list<string>>}  $config
     * @return array{directory?: string, command?: string}|null
     */
    private function programOf(string $name, array $config): ?array
    {
        $parts = explode(':', $name, 2);
        $group = $parts[0];
        $process = $parts[1] ?? null;

        if ($process === null) {
            return $config['programs'][$group] ?? null;
        }

        if (isset($config['programs'][$group])) {
            return $config['programs'][$group];
        }

        $members = $config['groups'][$group] ?? [];

        if (count($members) === 1) {
            return $config['programs'][$members[0]] ?? null;
        }

        foreach ([$process, preg_replace('/_\d+$/', '', $process) ?? $process] as $candidate) {
            if (in_array($candidate, $members, true) && isset($config['programs'][$candidate])) {
                return $config['programs'][$candidate];
            }
        }

        return null;
    }

    /**
     * The directory is also compared unresolved: during a deploy `current`
     * already points at the next release while the scheduler still runs
     * from the previous one.
     *
     * @param  array{directory?: string, command?: string}  $program
     * @param  list<string>  $roots
     */
    private function owns(array $program, array $roots): ?bool
    {
        $directory = rtrim($program['directory'] ?? '', '/');

        if ($directory !== '' && array_intersect(array_filter([$directory, $this->host->realpath($directory)]), $roots) !== []) {
            return true;
        }

        $command = $program['command'] ?? '';

        foreach ($roots as $root) {
            if (preg_match('#'.preg_quote($root, '#').'(?=/|\s|$)#', $command) === 1) {
                return true;
            }
        }

        if ($directory !== '' && preg_match('#(?:^|\s)(?:\./)?artisan(?=\s|$)#', $command) === 1) {
            foreach ($roots as $root) {
                if (str_starts_with($root, $directory.'/')) {
                    return null;
                }
            }
        }

        return false;
    }

    /**
     * Where the app runs from. With zero downtime deploys the base path is
     * `{site}/releases/{release}`, and a daemon may run from the site or
     * its `current` link instead.
     *
     * @return list<string>
     */
    private function roots(): array
    {
        $basePath = rtrim(base_path(), '/');

        if (preg_match('#^(.+)/releases/[^/]+$#', $basePath, $match) !== 1) {
            return [$basePath];
        }

        return [$basePath, $match[1], "{$match[1]}/current"];
    }

    /**
     * Only what an own process runs, never its full command line: the
     * artisan command, or else the name of the executable.
     */
    private function command(?string $command): ?string
    {
        $command = trim((string) preg_replace('/\s+/', ' ', (string) $command));

        if ($command === '') {
            return null;
        }

        if (preg_match('#(?:^|[\s/])artisan (.+)$#', $command, $match) === 1) {
            return mb_substr(trim($match[1]), 0, self::MAX_LENGTH);
        }

        return mb_substr(basename(explode(' ', $command)[0]), 0, self::MAX_LENGTH);
    }

    /**
     * The programs and groups of Supervisor's config, null when no file of
     * it could be read.
     *
     * @return array{programs: array<string, array{directory?: string, command?: string}>, groups: array<string, list<string>>}|null
     */
    private function config(): ?array
    {
        $config = ['programs' => [], 'groups' => []];
        $read = false;

        foreach ([self::MAIN_CONFIG, ...$this->host->files(self::INCLUDED_CONFIGS)] as $path) {
            $contents = $this->host->contents($path);

            if ($contents === null) {
                continue;
            }

            $read = true;

            foreach ($this->sections($contents) as $section => $values) {
                if (str_starts_with($section, 'program:')) {
                    $config['programs'][substr($section, 8)] = array_intersect_key($values, ['directory' => true, 'command' => true]);
                } elseif (str_starts_with($section, 'group:')) {
                    $config['groups'][substr($section, 6)] = array_values(array_filter(array_map('trim', explode(',', $values['programs'] ?? ''))));
                }
            }
        }

        return $read ? $config : null;
    }

    /**
     * Supervisor's INI dialect, which `parse_ini_string` does not read:
     * `key=value` or `key: value`, a `;` or `#` at the start of a line or
     * after whitespace starts a comment, and an indented line continues
     * the value above it.
     *
     * @return array<string, array<string, string>>
     */
    private function sections(string $contents): array
    {
        $sections = [];
        $section = null;
        $key = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = rtrim((string) preg_replace('/(^|\s)[;#].*$/', '', $line));

            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^\s/', $line) === 1) {
                if ($section !== null && $key !== null) {
                    $sections[$section][$key] .= ' '.trim($line);
                }

                continue;
            }

            if (preg_match('/^\[(.+)\]$/', $line, $match) === 1) {
                $section = trim($match[1]);
                $key = null;
                $sections[$section] ??= [];

                continue;
            }

            if ($section !== null && preg_match('/^([^=:]+?)\s*[=:]\s*(.*)$/', $line, $match) === 1) {
                $key = strtolower($match[1]);
                $sections[$section][$key] = $match[2];
            }
        }

        return $sections;
    }

    private function firstLine(string $output): ?string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return null;
    }

    /** @return array{processes: null, others: null, error: string} */
    private function failed(string $message): array
    {
        return ['processes' => null, 'others' => null, 'error' => $this->error($message)];
    }
}
