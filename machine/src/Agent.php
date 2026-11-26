<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * One run of the machine agent, started by cron every minute.
 *
 * A tick reads the machine and its processes once and sends them to
 * PingPong. It never walks the files on a disk: how full a disk is comes
 * from the filesystem itself, which answers at once however many files it
 * holds.
 */
class Agent
{
    /** The release that ships it, without its `v`: publishing a release whose tag differs attaches no phar. */
    public const VERSION = '1.11.0';

    public const SCHEMA = 1;

    /** How often a system installation reports its local account inventory. */
    public const UNIX_USERS_EVERY_SECONDS = 3600;

    public function __construct(
        private Config $config,
        private Client $client,
        private State $state,
        private Vitals $vitals,
        private ProcessTable $processTable,
        private ProcessReport $processReport,
        private Users $users,
        private PackageLog $packageLog = new PackageLog,
    ) {}

    /** Whether PingPong took the tick. */
    public function tick(?float $now = null): bool
    {
        $now ??= microtime(true);
        $seesEveryone = $this->processTable->seesEveryone();
        $signals = $this->vitals->signals($this->config->system ? '/' : Config::home());
        $swapping = ($signals['memory']['swap_used_bytes'] ?? 0) > 0;

        $processes = null;
        $uptime = $this->processTable->uptime();
        $table = $this->processTable->read($swapping);

        if ($table !== null && $uptime !== null) {
            $previous = $this->state->get('processes');
            $built = $this->processReport->build(
                $table,
                $this->validSnapshot($previous),
                $now,
                $uptime,
                $seesEveryone,
                $this->validWatchUsers($this->state->get('watch_users')),
                $this->validWatchProcesses($this->state->get('watch_processes')),
                $this->users->current(),
            );
            $processes = $built['report'];
            $this->state->set('processes', $built['snapshot']);
        }

        $gap = $this->state->get('unreachable');
        $lastInventory = $this->state->get('unix_users_sent_at');
        $unixUsers = $this->config->system && (! is_int($lastInventory) || $now - $lastInventory >= self::UNIX_USERS_EVERY_SECONDS)
            ? $this->users->localAccounts()
            : null;

        $packageChanges = $this->packageLog->read($this->state->get('package_log'));

        $response = $this->client->post('api/machine/tick', [
            ...$this->envelope($now),
            'signals' => array_filter($signals, fn (mixed $signal): bool => $signal !== null),
            'host' => [...$this->vitals->host($this->state), 'package_changes' => $packageChanges['changes'] ?? null],
            'processes' => $processes,
            'oom_kill_events' => $this->config->system ? $this->vitals->oomKills((int) $now) : [],
            'connectivity' => is_array($gap) ? $gap : null,
            ...($unixUsers === null ? [] : ['unix_users' => $unixUsers]),
        ]);

        $sent = $response !== null && $response['status'] >= 200 && $response['status'] < 300;
        if ($sent && $unixUsers !== null) {
            $this->state->set('unix_users_sent_at', (int) $now);
        }

        if ($sent) {
            $this->rememberWatchRules($response['body']);
        }

        /* A batch PingPong did not take is read again next tick. One
           without changes loses nothing when skipped, which is how the
           first tick keeps the end of the log it starts at. */
        if ($packageChanges !== null && ($sent || $packageChanges['changes'] === [])) {
            $this->state->set('package_log', $packageChanges['cursor']);
        }

        $this->rememberReach($response, $now);
        $this->state->save();

        return $sent;
    }

    /**
     * Keeps the users and processes PingPong asks this machine to report even
     * when idle or stopped. Omitted fields reset the corresponding watch list
     * to empty, matching PingPong omitting empty arrays and keeping older
     * servers compatible.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function rememberWatchRules(array $body): void
    {
        $this->state->set('watch_users', $this->validWatchUsers($body['watch_users'] ?? null));
        $this->state->set('watch_processes', $this->validWatchProcesses($body['watch_processes'] ?? null));
    }

    /**
     * @return list<string>
     */
    private function validWatchUsers(mixed $users): array
    {
        if (! is_array($users)) {
            return [];
        }

        $valid = [];

        foreach ($users as $user) {
            if (! is_string($user)) {
                continue;
            }

            $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $user));

            if ($clean === '' || strlen($clean) > ProcessTable::MAX_NAME_LENGTH) {
                continue;
            }

            $valid[$clean] = $clean;
        }

        return array_values($valid);
    }

    /**
     * @return list<array{user: string, process: string}>
     */
    private function validWatchProcesses(mixed $processes): array
    {
        if (! is_array($processes)) {
            return [];
        }

        $valid = [];

        foreach ($processes as $entry) {
            if (! is_array($entry) || ! is_string($entry['user'] ?? null) || ! is_string($entry['process'] ?? null)) {
                continue;
            }

            $user = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $entry['user']));
            $process = ProcessTable::sanitizeName($entry['process']);

            if ($user === '' || strlen($user) > ProcessTable::MAX_NAME_LENGTH || $process === '') {
                continue;
            }

            $valid["{$user}\0{$process}"] = ['user' => $user, 'process' => $process];
        }

        return array_values($valid);
    }

    /**
     * Keeps how long PingPong could not be reached and why, so the first
     * tick that gets through can say so: PingPong then tells a machine that
     * lost its network from one that was gone. A tick PingPong refused (a
     * revoked key, a payload it could not read) did reach it, so it is no
     * gap; a 5xx or a 429 is.
     *
     * @param  array{status: int, body: array<array-key, mixed>}|null  $response
     */
    private function rememberReach(?array $response, float $now): void
    {
        $status = $response['status'] ?? 0;
        $error = match (true) {
            $response === null => $this->client->lastError ?? 'other',
            $status >= 500 => 'http_5xx',
            $status === 429 => 'rate_limited',
            default => null,
        };

        if ($error === null) {
            $this->state->set('unreachable', null);

            return;
        }

        $gap = $this->state->get('unreachable');
        $at = gmdate('Y-m-d\TH:i:s\Z', (int) $now);

        $this->state->set('unreachable', [
            'unreachable_since' => is_array($gap) && is_string($gap['unreachable_since'] ?? null) ? $gap['unreachable_since'] : $at,
            'unreachable_until' => $at,
            'failed_attempts' => (is_array($gap) && is_int($gap['failed_attempts'] ?? null) ? $gap['failed_attempts'] : 0) + 1,
            'last_error' => $error,
        ]);
    }

    /**
     * Who sends this, in every request: the machine by its hostname and
     * machine id, and the Unix user the agent runs as, since every user of a
     * shared host can install one of their own. `mode` says which: `system`
     * for the one root installed, which reads the whole machine, `user` for
     * one a user installed.
     *
     * @return array<string, mixed>
     */
    private function envelope(float $now): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'server' => gethostname() ?: 'unknown',
            ...$this->vitals->identity(),
            'unix_user' => $this->users->current(),
            'mode' => $this->config->system ? 'system' : 'user',
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $now),
        ];
    }

    /**
     * @return array{taken_at: float, uptime: float, ticks: array<string, int>}|null
     */
    private function validSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot) || ! is_numeric($snapshot['taken_at'] ?? null) || ! is_numeric($snapshot['uptime'] ?? null) || ! is_array($snapshot['ticks'] ?? null)) {
            return null;
        }

        $ticks = [];

        foreach ($snapshot['ticks'] as $key => $value) {
            if (is_int($value)) {
                $ticks[(string) $key] = $value;
            }
        }

        return ['taken_at' => (float) $snapshot['taken_at'], 'uptime' => (float) $snapshot['uptime'], 'ticks' => $ticks];
    }
}
