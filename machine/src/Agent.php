<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * One run of the machine agent, started by cron every minute.
 *
 * A tick reads the machine and its processes once and sends them to
 * PingPong. Once an hour, or sooner when PingPong asks for it while a disk
 * Finding is open, it then measures the sites, after the tick is sent and in
 * a request of its own, so a slow `du` never delays the tick. A lock keeps a
 * second run from starting a scan while one is still going.
 */
class Agent
{
    public const VERSION = '1.0.0';

    public const SCHEMA = 1;

    /** How often the sites are measured on their own. */
    public const SITES_EVERY_SECONDS = 3600;

    /** How often PingPong may ask for them, while a disk fills up. */
    public const SITES_ON_REQUEST_EVERY_SECONDS = 900;

    /** Above this share of the last minute waiting on IO the machine is too busy for a scan. */
    public const BUSY_IO_PRESSURE = 10.0;

    /** Above this load (5 minutes) per core the machine is too busy for a scan. */
    public const BUSY_LOAD_PER_CORE = 1.0;

    /** However busy the machine stays, the sites are measured after this long. */
    public const SITES_AT_LEAST_EVERY_SECONDS = 6 * 3600;

    /** The setcap copy of `du` install.sh makes, which may read every home directory. */
    public const PRIVILEGED_DU = Config::SYSTEM_LIBRARY.'/du';

    public function __construct(
        private Config $config,
        private Client $client,
        private State $state,
        private Vitals $vitals,
        private ProcessTable $processTable,
        private ProcessReport $processReport,
        private SiteSizes $siteSizes,
        private Runner $runner,
        private Users $users,
    ) {}

    /**
     * @return array{sent: bool, measure_sites: bool, busy: bool}
     */
    public function tick(?float $now = null): array
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
            $built = $this->processReport->build($table, $this->validSnapshot($previous), $now, $uptime, $seesEveryone);
            $processes = $built['report'];
            $this->state->set('processes', $built['snapshot']);
        }

        $gap = $this->state->get('unreachable');

        $response = $this->client->post('api/machine/tick', [
            ...$this->envelope($now),
            'signals' => array_filter($signals, fn (mixed $signal): bool => $signal !== null),
            'host' => $this->vitals->host($this->state),
            'processes' => $processes,
            'oom_kill_events' => $this->config->system ? $this->vitals->oomKills((int) $now) : [],
            'connectivity' => is_array($gap) ? $gap : null,
        ]);

        $sent = $response !== null && $response['status'] >= 200 && $response['status'] < 300;
        $this->rememberReach($response, $now);
        $this->state->save();

        return [
            'sent' => $sent,
            'measure_sites' => $sent && ($response['body']['measure_sites'] ?? false) === true,
            'busy' => $this->busy($signals),
        ];
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
     * Whether the machine already waits on its disk or its CPUs. A scan
     * reads every folder and `ionice` only holds it back on some IO
     * schedulers, so it waits for a quieter minute rather than add to it.
     *
     * @param  array{load: ?array{'1m': float, '5m': float, '15m': float}, pressure: ?array<string, ?float>}  $signals
     */
    public function busy(array $signals): bool
    {
        $cores = $this->vitals->identity()['cores'] ?? null;
        $load = $signals['load']['5m'] ?? null;

        return ($signals['pressure']['io_some'] ?? 0) > self::BUSY_IO_PRESSURE
            || ($load !== null && $cores !== null && $load / max(1, $cores) > self::BUSY_LOAD_PER_CORE);
    }

    /**
     * Whether the sites are due: an hour after the last scan, or a quarter
     * of an hour when PingPong asked; on a busy machine not until six hours
     * have passed.
     */
    public function sitesDue(bool $requested, bool $busy = false, ?float $now = null): bool
    {
        clearstatcache(true, $this->scannedAtFile());
        $last = @filemtime($this->scannedAtFile());
        $since = $last === false ? PHP_FLOAT_MAX : ($now ?? microtime(true)) - $last;

        if ($busy) {
            return $since >= self::SITES_AT_LEAST_EVERY_SECONDS;
        }

        return $since >= ($requested ? self::SITES_ON_REQUEST_EVERY_SECONDS : self::SITES_EVERY_SECONDS);
    }

    /**
     * Measures the sites and the databases and sends them. Null when another
     * run is still measuring, or there is no `du`.
     */
    public function sites(?float $now = null): ?bool
    {
        $lock = $this->state->prepare() ? @fopen($this->state->directory().'/sites.lock', 'c') : false;

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }

        try {
            $du = $this->du();

            if ($du === null) {
                return null;
            }

            $started = microtime(true);
            $sites = $this->siteSizes->sites($this->roots(), $du);
            $databases = $this->config->system ? $this->siteSizes->databases($du) : null;

            /* Kept before sending: a scan PingPong did not take is not run
               again every minute. As the time of a file of its own, never in
               the state the ticks write (a scan takes minutes, and neither
               may write over the other), and by touching it, which needs no
               free space: a full disk is when this matters most. */
            @touch($this->scannedAtFile(), (int) ($now ?? microtime(true)));

            $response = $this->client->post('api/machine/sites', [
                ...$this->envelope($now ?? microtime(true)),
                'sites' => $sites['entries'],
                'complete' => $sites['complete'],
                'unmeasured' => $sites['unmeasured'],
                'databases' => $databases,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);

            return $response !== null && $response['status'] >= 200 && $response['status'] < 300;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function scannedAtFile(): string
    {
        return $this->state->directory().'/sites_at';
    }

    /**
     * Who sends this, in every request: the machine by its hostname and
     * machine id, and the Unix user the agent runs as, since every user of a
     * shared host can install one of their own. `mode` says which: `system`
     * for the one root installed, which reads the whole machine's disk and
     * sites, `user` for one a user installed, which reads their home.
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
     * Where the sites live: every home directory and `/var/www` for the
     * agent root installed for the whole machine, the home directory of the
     * user who installed it for themselves.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        if ($this->config->roots !== null) {
            return $this->config->roots;
        }

        if (! $this->config->system) {
            return [Config::home()];
        }

        return array_values(array_filter(['/home', '/var/www'], fn (string $root): bool => @is_dir($root)));
    }

    private function du(): ?string
    {
        foreach ([$this->config->du, self::PRIVILEGED_DU] as $candidate) {
            if ($candidate !== null && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return $this->runner->find('du');
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
