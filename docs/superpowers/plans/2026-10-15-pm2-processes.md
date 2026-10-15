# PM2 processes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The tick reports every PM2 process of the server, PingPong learns them per server and raises an Incident when a known process stops or disappears, and my.tiemliner.com drops its own `pm2:check` mail.

**Architecture:** The Agent gets a `pm2` signal (platform fact with `error`) that reads `pm2 jlist` only when a daemon runs. PingPong validates and stores the list on the sample, upserts the names into `agent_pm2_processes`, and judges each sample with the existing App health Incident machinery (`RaiseAppHealthIncident`, `ResolveAppHealthIncident`). A per process `ignored_at` lets a user stop watching a name.

**Tech Stack:** PHP 8.2+, Laravel 12/13, Pest, Orchestra Testbench (Agent); Laravel 13, Livewire 4, PostgreSQL, Pest (PingPong).

**Spec:** `docs/superpowers/specs/2026-10-15-pm2-processes-design.md` (in `pingpong-agent`)

## Repositories and worktrees

| Part | Repository | Worktree | Branch | Base |
|---|---|---|---|---|
| A | `pingpong-agent` | `/Users/kylianwester/conductor/workspaces/pingpong-agent/lagos` | `feature/pm2-monitor-check-pingpong` | `main` |
| B | `pingpong.kobaltdigital.nl` | create: `git -C ~/Sites/Kobalt/Laravel/pingpong.kobaltdigital.nl worktree add ~/conductor/workspaces/pingpong.kobaltdigital.nl/pm2-processes -b feature/pm2-processes origin/main`, then `cp ~/Sites/Kobalt/Laravel/pingpong.kobaltdigital.nl/.env .` and `composer install` | `feature/pm2-processes` | `main` |
| C | `my.tiemliner.com` | create: `git -C ~/Sites/Kobalt/Laravel/my.tiemliner.com worktree add ~/conductor/workspaces/my.tiemliner.com/pingpong-agent -b feature/pingpong-agent origin/develop` | `feature/pingpong-agent` | `develop` |

Test commands: Agent `vendor/bin/pest`, `composer analyse`, `composer format`. PingPong `php artisan test --compact <file>` (PostgreSQL database `pingpong_test`), `vendor/bin/pint --dirty`.

## Global Constraints

- Payload `schema` stays `1`. `pm2` is an optional key.
- Only `id`, `name`, `status`, `restarts`, `uptime_seconds`, `memory_bytes` per process leave the server. `pm2_env` is never sent.
- `processes` at most 100, sorted by `id`. `error` at most 255 characters.
- `pm2 jlist` timeout 3 seconds. No PM2 command runs unless `$PM2_HOME/pm2.pid` (default `$HOME/.pm2/pm2.pid`) exists.
- Not on `PATH`: signal left out. Daemon not running: `processes: []`. Home unknown, timeout, failed exit, no JSON list: `processes: null` plus `error`.
- PingPong: a process is known by (`monitor_id`, `server`, `name`). A name is down when a watched known name is missing from the list or any instance of it is not `online`.
- Incident after `MonitorAppHealthConfig::unreachableAfterSamples()` down samples of one server in a row; cause `auto_app_pm2`, label "PM2 process down".
- `processes: null` or no `pm2` key: unknown, opens and resolves nothing, breaks a streak.
- Docs and README: never use dashes as punctuation.
- Commits: atomic, conventional prefix, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.

## Review Focus

1. PM2 prints a notice line before the JSON ("In-memory PM2 is out-of-date"): the list must still parse. Test in Task A2.
2. A process in cluster mode is listed several times under one name with one instance `errored`: the name is down. Test in Task B3.
3. A user ignores the only down process while its Incident is open: the Incident resolves at once, without waiting for a tick. Test in Task B4.
4. A tick whose `pm2.processes` is `null` between down ticks: the streak breaks and no Incident opens. Test in Task B3.
5. A second server that never ran PM2 (no `pm2` key) must not hold an Incident open nor clear it. Test in Task B3.

---

## Part A: pingpong-agent

### Task A1: Host finds pm2 and its daemon

**Files:**
- Modify: `composer.json` (require)
- Modify: `src/Host.php`
- Modify: `tests/Fakes/FakeHost.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Unit/HostTest.php`

**Interfaces:**
- Produces: `Host::pm2Binary(): ?string`, `Host::pm2DaemonRuns(): ?bool`, `Host::pm2Home(): ?string`. `FakeHost` named args `pm2Binary: ?string = null`, `pm2DaemonRuns: ?bool = true`.

- [ ] **Step 1: Require illuminate/process**

In `composer.json` `require`, after `illuminate/http`, add `"illuminate/process": "^12.0|^13.0",`. Run `composer update illuminate/process --no-interaction`. Expected: installs without conflicts.

- [ ] **Step 2: Write the failing tests** (append to `tests/Unit/HostTest.php`)

```php
it('finds pm2 on the PATH without running anything', function () {
    $directory = sys_get_temp_dir().'/pingpong-pm2-'.uniqid();
    mkdir($directory);
    touch("{$directory}/pm2");
    chmod("{$directory}/pm2", 0755);
    $path = getenv('PATH');
    putenv("PATH=/nonexistent:{$directory}");

    try {
        expect((new Host)->pm2Binary())->toBe("{$directory}/pm2");
    } finally {
        putenv("PATH={$path}");
        unlink("{$directory}/pm2");
        rmdir($directory);
    }
});

it('gives no pm2 when it is not on the PATH', function () {
    $path = getenv('PATH');
    putenv('PATH=/nonexistent');

    try {
        expect((new Host)->pm2Binary())->toBeNull();
    } finally {
        putenv("PATH={$path}");
    }
});

it('reads the PM2 home from PM2_HOME, else HOME', function () {
    $pm2Home = getenv('PM2_HOME');
    $home = getenv('HOME');

    try {
        putenv('PM2_HOME=/srv/pm2/');
        expect((new Host)->pm2Home())->toBe('/srv/pm2');

        putenv('PM2_HOME');
        putenv('HOME=/home/forge');
        expect((new Host)->pm2Home())->toBe('/home/forge/.pm2');

        putenv('HOME');
        expect((new Host)->pm2Home())->toBeNull();
    } finally {
        $pm2Home === false ? putenv('PM2_HOME') : putenv("PM2_HOME={$pm2Home}");
        $home === false ? putenv('HOME') : putenv("HOME={$home}");
    }
});

it('knows whether the PM2 daemon runs from its pid file', function () {
    $directory = sys_get_temp_dir().'/pingpong-pm2-home-'.uniqid();
    mkdir($directory);
    $pm2Home = getenv('PM2_HOME');
    putenv("PM2_HOME={$directory}");

    try {
        expect((new Host)->pm2DaemonRuns())->toBeFalse();

        touch("{$directory}/pm2.pid");
        expect((new Host)->pm2DaemonRuns())->toBeTrue();
    } finally {
        $pm2Home === false ? putenv('PM2_HOME') : putenv("PM2_HOME={$pm2Home}");
        @unlink("{$directory}/pm2.pid");
        rmdir($directory);
    }
});

it('does not know whether the PM2 daemon runs without a home', function () {
    $pm2Home = getenv('PM2_HOME');
    $home = getenv('HOME');
    putenv('PM2_HOME');
    putenv('HOME');

    try {
        expect((new Host)->pm2DaemonRuns())->toBeNull();
    } finally {
        $pm2Home === false ? putenv('PM2_HOME') : putenv("PM2_HOME={$pm2Home}");
        $home === false ? putenv('HOME') : putenv("HOME={$home}");
    }
});
```

- [ ] **Step 3: Run to see them fail**

Run: `vendor/bin/pest tests/Unit/HostTest.php`
Expected: FAIL, `Call to undefined method KobaltDigital\PingPong\Host::pm2Binary()`.

- [ ] **Step 4: Implement in `src/Host.php`** (before the private `loadShape`)

```php
    /**
     * Where `pm2` is on the PATH of this process, null when it is not. Looked
     * up by hand rather than with Symfony's ExecutableFinder, which falls back
     * to a shell: a server without PM2 pays one PATH scan per tick, nothing more.
     */
    public function pm2Binary(): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/').'/pm2';

            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a PM2 daemon runs for this user, read from its pid file so no
     * PM2 command has to run: every one of them starts a daemon when none
     * does. Null when nothing says where the daemon keeps its files.
     */
    public function pm2DaemonRuns(): ?bool
    {
        $home = $this->pm2Home();

        if ($home === null) {
            return null;
        }

        return @is_file("{$home}/pm2.pid");
    }

    public function pm2Home(): ?string
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
```

- [ ] **Step 5: Extend `tests/Fakes/FakeHost.php`**

Add two constructor parameters after `$cores` and two overrides:

```php
        private ?int $cores = 4,
        private ?string $pm2Binary = null,
        private ?bool $pm2DaemonRuns = true,
    ) {}

    public function pm2Binary(): ?string
    {
        return $this->pm2Binary;
    }

    public function pm2DaemonRuns(): ?bool
    {
        return $this->pm2DaemonRuns;
    }
```

- [ ] **Step 6: Prevent stray processes in `tests/TestCase.php`**

Add `use Illuminate\Support\Facades\Process;` and, after `Http::preventStrayRequests();`, `Process::preventStrayProcesses();`.

- [ ] **Step 7: Run the suite**

Run: `vendor/bin/pest && composer analyse`
Expected: all PASS, no PHPStan errors.

- [ ] **Step 8: Commit**

```bash
git add composer.json src/Host.php tests/Fakes/FakeHost.php tests/TestCase.php tests/Unit/HostTest.php
git commit -m "feat: host finds pm2 and its daemon"
```

### Task A2: pm2 signal

**Files:**
- Create: `src/Signals/Pm2.php`
- Modify: `config/pingpong-agent.php`
- Test: `tests/Feature/Signals/Pm2Test.php`

**Interfaces:**
- Consumes: `Host::pm2Binary()`, `Host::pm2DaemonRuns()`, `FakeHost` named args from A1.
- Produces: collector `KobaltDigital\PingPong\Signals\Pm2`, name `pm2`, returns `null` or `array{processes: list<array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}>|null, error: ?string}`. Constants `Pm2::MAX_PROCESSES = 100`, `Pm2::TIMEOUT_SECONDS = 3`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Signals/Pm2Test.php`)

```php
<?php

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use KobaltDigital\PingPong\Signals\Pm2;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

function pm2Signal(?string $binary = '/usr/bin/pm2', ?bool $daemonRuns = true): Pm2
{
    return new Pm2(new FakeHost(pm2Binary: $binary, pm2DaemonRuns: $daemonRuns));
}

function jlistProcess(int $id, string $name, string $status = 'online', array $overrides = []): array
{
    return array_replace_recursive([
        'pid' => 1000 + $id,
        'name' => $name,
        'pm_id' => $id,
        'monit' => ['memory' => 52428800, 'cpu' => 0.3],
        'pm2_env' => [
            'status' => $status,
            'restart_time' => 3,
            'pm_uptime' => now()->subDay()->getTimestampMs(),
            'env' => ['APP_KEY' => 'base64:secret'],
        ],
    ], $overrides);
}

beforeEach(fn () => $this->freezeSecond());

it('is left out when pm2 is not on the PATH, without running anything', function () {
    Process::fake();

    expect(pm2Signal(binary: null)->collect())->toBeNull();

    Process::assertNothingRan();
});

it('reports no processes when no daemon runs, without starting one', function () {
    Process::fake();

    expect(pm2Signal(daemonRuns: false)->collect())->toBe(['processes' => [], 'error' => null]);

    Process::assertNothingRan();
});

it('reports an error when it cannot tell where the daemon lives', function () {
    Process::fake();

    expect(pm2Signal(daemonRuns: null)->collect())
        ->toBe(['processes' => null, 'error' => 'Neither PM2_HOME nor HOME is set, so the PM2 daemon cannot be found']);

    Process::assertNothingRan();
});

it('reports every process by id with only the facts PingPong reads', function () {
    Process::fake(['*' => Process::result(json_encode([
        jlistProcess(1, 'queue-pusher', 'stopped'),
        jlistProcess(0, 'queue-worker'),
    ]))]);

    $signal = pm2Signal();

    expect($signal->name())->toBe('pm2')
        ->and($signal->collect())->toBe([
            'processes' => [
                ['id' => 0, 'name' => 'queue-worker', 'status' => 'online', 'restarts' => 3, 'uptime_seconds' => 86400, 'memory_bytes' => 52428800],
                ['id' => 1, 'name' => 'queue-pusher', 'status' => 'stopped', 'restarts' => 3, 'uptime_seconds' => null, 'memory_bytes' => 52428800],
            ],
            'error' => null,
        ]);

    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/pm2', 'jlist'] && $process->timeout === Pm2::TIMEOUT_SECONDS);
});

it('reads the list after notices PM2 prints before it', function () {
    Process::fake(['*' => Process::result(">>>> In-memory PM2 is out-of-date, do:\n>>>> \$ pm2 update\n".json_encode([jlistProcess(0, 'queue-worker')]))]);

    expect(pm2Signal()->collect()['processes'])->toHaveCount(1);
});

it('leaves out memory PM2 does not report', function () {
    Process::fake(['*' => Process::result(json_encode([jlistProcess(0, 'queue-worker', overrides: ['monit' => ['memory' => null]])]))]);

    expect(pm2Signal()->collect()['processes'][0]['memory_bytes'])->toBeNull();
});

it('keeps at most a hundred processes', function () {
    Process::fake(['*' => Process::result(json_encode(array_map(fn (int $id) => jlistProcess($id, "worker-{$id}"), range(0, 120))))]);

    $processes = pm2Signal()->collect()['processes'];

    expect($processes)->toHaveCount(Pm2::MAX_PROCESSES)
        ->and(end($processes)['id'])->toBe(99);
});

it('reports a failed jlist with its error output', function () {
    Process::fake(['*' => Process::result(errorOutput: "spawn node ENOENT\n", exitCode: 1)]);

    expect(pm2Signal()->collect())->toBe(['processes' => null, 'error' => 'spawn node ENOENT']);
});

it('reports a failed jlist without error output by its exit code', function () {
    Process::fake(['*' => Process::result(exitCode: 2)]);

    expect(pm2Signal()->collect())->toBe(['processes' => null, 'error' => 'pm2 jlist exited with code 2']);
});

it('reports output without a JSON list', function () {
    Process::fake(['*' => Process::result('{"not": "a list"}')]);

    expect(pm2Signal()->collect())->toBe(['processes' => null, 'error' => 'pm2 jlist printed no JSON list']);
});

it('reports a jlist that does not answer in time', function () {
    Process::fake(['*' => fn () => throw new ProcessTimedOutException(
        new SymfonyProcessTimedOutException(new SymfonyProcess(['pm2', 'jlist']), SymfonyProcessTimedOutException::TYPE_GENERAL),
        new FakeProcessResult,
    )]);

    expect(pm2Signal()->collect())->toBe(['processes' => null, 'error' => 'pm2 jlist did not answer within 3 seconds']);
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(Pm2::class);
});
```

Check the `ProcessTimedOutException` constructor in `vendor/illuminate/process/Exceptions/ProcessTimedOutException.php` before running; if Laravel's fake does not let a closure throw, assert the timeout through a `FakeHost` subclass instead and note it in the commit body.

- [ ] **Step 2: Run to see them fail**

Run: `vendor/bin/pest tests/Feature/Signals/Pm2Test.php`
Expected: FAIL, `Class "KobaltDigital\PingPong\Signals\Pm2" not found`.

- [ ] **Step 3: Implement `src/Signals/Pm2.php`**

```php
<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
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

    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'pm2';
    }

    /** @return array{processes: list<array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}>|null, error: ?string}|null */
    public function collect(): ?array
    {
        $binary = $this->host->pm2Binary();

        if ($binary === null) {
            return null;
        }

        $daemonRuns = $this->host->pm2DaemonRuns();

        if ($daemonRuns === null) {
            return $this->failed('Neither PM2_HOME nor HOME is set, so the PM2 daemon cannot be found');
        }

        if (! $daemonRuns) {
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
     * PM2 prints notices such as "In-memory PM2 is out-of-date" before the
     * JSON, so the list starts at the first line that opens one.
     *
     * @return list<mixed>|null
     */
    private function decode(string $output): ?array
    {
        $start = str_starts_with($output, '[') ? 0 : strpos($output, "\n[");

        if ($start === false) {
            return null;
        }

        $list = json_decode(substr($output, $start), true);

        return is_array($list) && array_is_list($list) ? $list : null;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}>
     */
    private function processes(array $list): array
    {
        $now = now()->getTimestampMs();
        $processes = [];

        foreach ($list as $process) {
            if (! is_array($process) || ! is_int($process['pm_id'] ?? null) || ! is_string($process['name'] ?? null)) {
                continue;
            }

            $env = is_array($process['pm2_env'] ?? null) ? $process['pm2_env'] : [];
            $status = is_string($env['status'] ?? null) ? $env['status'] : 'unknown';
            $startedAt = $env['pm_uptime'] ?? null;
            $memory = $process['monit']['memory'] ?? null;

            $processes[] = [
                'id' => $process['pm_id'],
                'name' => $process['name'],
                'status' => $status,
                'restarts' => is_int($env['restart_time'] ?? null) ? $env['restart_time'] : 0,
                'uptime_seconds' => $status === 'online' && is_numeric($startedAt) ? max(0, intdiv($now - (int) $startedAt, 1000)) : null,
                'memory_bytes' => is_int($memory) ? $memory : null,
            ];
        }

        usort($processes, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($processes, 0, self::MAX_PROCESSES);
    }

    /** @return array{processes: null, error: string} */
    private function failed(string $message): array
    {
        return ['processes' => null, 'error' => $this->error($message)];
    }
}
```

- [ ] **Step 4: Add to `config/pingpong-agent.php`** after `Signals\AppState::class,`: `Signals\Pm2::class,`

- [ ] **Step 5: Run**

Run: `vendor/bin/pest && composer analyse`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Signals/Pm2.php config/pingpong-agent.php tests/Feature/Signals/Pm2Test.php
git commit -m "feat: pm2 processes in the tick"
```

### Task A3: Payload contract, fixture and README

**Files:**
- Modify: `tests/Fixtures/schema-1/tick.json`
- Modify: `tests/Feature/PingCommandTest.php` (the "sends a tick shaped like the schema 1 fixture" test)
- Modify: `docs/payloads.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: `Signals\Pm2`, `FakeHost(pm2Binary:)`.
- Produces: `tests/Fixtures/schema-1/tick.json` with `signals.pm2`, which Part B copies verbatim.

- [ ] **Step 1: Add `pm2` to the fixture** after `"app"` in `signals`:

```json
        "app": {
            "maintenance": false,
            "debug": false
        },
        "pm2": {
            "processes": [
                {
                    "id": 0,
                    "name": "queue-worker",
                    "status": "online",
                    "restarts": 3,
                    "uptime_seconds": 86400,
                    "memory_bytes": 52428800
                }
            ],
            "error": null
        }
```

- [ ] **Step 2: Run the shape test to see it fail**

Run: `vendor/bin/pest tests/Feature/PingCommandTest.php --filter="shaped like the schema 1 fixture"`
Expected: FAIL, the sent payload lacks `pm2`.

- [ ] **Step 3: Give the shape test a PM2** at the top of that test, after `config()->set('pingpong-agent.key', ...)`:

```php
    $this->app->instance(Host::class, new FakeHost(pm2Binary: '/usr/bin/pm2'));
    Process::fake(['*' => Process::result(json_encode([[
        'name' => 'queue-worker',
        'pm_id' => 0,
        'monit' => ['memory' => 52428800],
        'pm2_env' => ['status' => 'online', 'restart_time' => 3, 'pm_uptime' => now()->subDay()->getTimestampMs()],
    ]]))]);
```

with `use Illuminate\Support\Facades\Process;`, `use KobaltDigital\PingPong\Host;`, `use KobaltDigital\PingPong\Tests\Fakes\FakeHost;`.

- [ ] **Step 4: Run**

Run: `vendor/bin/pest`
Expected: all PASS.

- [ ] **Step 5: Document in `docs/payloads.md`**

1. Add the `pm2` block of Step 1 to the tick example.
2. In the conventions list, add `pm2` to the platform facts: "**Platform facts** (`load`, `memory`, `pm2`, and the backlog keys of `queue`) ...". Add after that list: "`pm2` is a platform fact that also carries an `error`: left out without PM2, an `error` when PM2 is there but its list could not be read."
3. Add a section after `### signals.app`:

```markdown
### `signals.pm2`

Every process PM2 runs for the user that runs the scheduler, read with `pm2 jlist` with a 3 second timeout. Left out when `pm2` is not on the `PATH`. The Agent runs no PM2 command while no daemon runs (`$PM2_HOME/pm2.pid`, `PM2_HOME` defaulting to `~/.pm2`, is missing), since every PM2 command starts a daemon when none runs; `processes` is then an empty list, as no process runs. Notices PM2 prints before its JSON are skipped. Only the keys below leave the server: `pm2 jlist` also carries the environment of every process, which is never sent.

| Key | Type | Meaning |
|---|---|---|
| `processes` | array or null | Every listed process, sorted by `id`, at most 100. An empty list when no daemon runs. `null` when the list could not be read |
| `processes[].id` | integer | `pm_id` |
| `processes[].name` | string | `name`. Instances of one app in cluster mode share it |
| `processes[].status` | string | `pm2_env.status`, for example `online`, `stopped`, `errored`, `launching` or `waiting restart` |
| `processes[].restarts` | integer | `pm2_env.restart_time` |
| `processes[].uptime_seconds` | integer or null | Seconds since the process started, while `online`. `null` otherwise |
| `processes[].memory_bytes` | integer or null | `monit.memory`. `null` when PM2 leaves it out |
| `error` | string or null | Why the list could not be read: no `PM2_HOME` or `HOME`, a timeout, a failed exit or output without a JSON list |

The cost is one `node` process per tick on a server that runs PM2, and a `PATH` scan and one file check on every other server.
```

- [ ] **Step 6: README**

Add `Signals\Pm2::class,` to the config example after `Signals\AppState::class,`, and after the **App** bullet add:

```markdown
- **PM2.** Every process PM2 runs for the scheduler's user, with its status, restarts, uptime and memory. Left out when PM2 is not installed. No PM2 command runs while no daemon does, so the Agent never starts one; the list is then empty. The environment PM2 keeps per process is never sent.
```

Change "The signals are cheap: a few syscalls, one file read and a few small queries." to "The signals are cheap: a few syscalls, one file read, a few small queries and, on a server that runs PM2, one `pm2 jlist`."

- [ ] **Step 7: Format, analyse, test**

Run: `composer format && composer analyse && vendor/bin/pest`
Expected: PASS, no format changes left.

- [ ] **Step 8: Commit**

```bash
git add tests/Fixtures/schema-1/tick.json tests/Feature/PingCommandTest.php docs/payloads.md README.md
git commit -m "docs: pm2 signal in the tick contract"
```

### Task A4: Release (manual, by Kylian)

- [ ] Open the PR for `feature/pm2-monitor-check-pingpong`, merge, tag a release and make it installable (Packagist or a VCS repository). The Agent has no tag and is not on Packagist yet, so Part C needs this first. Do not tag or publish without Kylian's go.

---

## Part B: pingpong.kobaltdigital.nl

Read `CLAUDE.md` and `docs/claude/check-pipelines.md` ("App health samples") first. Lazy loading throws outside production: eager load what views read.

### Task B1: Intake and storage of the pm2 signal

**Files:**
- Modify: `tests/Fixtures/agent/schema-1/tick.json` (copy of the Agent's)
- Create: `database/migrations/2026_10_15_120000_add_pm2_to_app_health.php`
- Modify: `app/Http/Requests/Api/Agent/TickRequest.php`
- Modify: `app/Models/AppHealthSample.php`
- Modify: `database/factories/AppHealthSampleFactory.php`
- Modify: `app/Actions/Agent/RecordAppHealthSample.php`
- Test: `tests/Feature/Controllers/Api/AgentPlatformSignalsTest.php`

**Interfaces:**
- Produces: columns `app_health_samples.pm2_processes` (jsonb, cast `array`) and `pm2_error`; table `agent_pm2_processes` (used by B2); `TickRequest::pm2(): ?array`; `RecordAppHealthSample::SIGNALS` contains `pm2`; factory state `AppHealthSampleFactory::pm2(array $processes)`.

- [ ] **Step 1: Copy the fixture**

```bash
cp /Users/kylianwester/conductor/workspaces/pingpong-agent/lagos/tests/Fixtures/schema-1/tick.json tests/Fixtures/agent/schema-1/tick.json
git diff --stat tests/Fixtures
```
Expected: only the `pm2` block added. If more differs, keep PingPong's other keys and add only `pm2`.

- [ ] **Step 2: Write the failing tests** (append to `AgentPlatformSignalsTest.php`)

```php
it('stores the pm2 processes of a tick, and an error when the list could not be read', function () {
    [$monitor, $token] = platformMonitor();

    platformTick($token, agentFixture('tick')['signals']);
    platformTick($token, ['pm2' => ['processes' => null, 'error' => str_repeat('x', 300)]], 'web-02');

    $samples = $monitor->appHealthSamples()->orderBy('server')->get();
    expect($samples[0]->pm2_processes)->toBe([['id' => 0, 'name' => 'queue-worker', 'status' => 'online', 'restarts' => 3, 'uptime_seconds' => 86400, 'memory_bytes' => 52428800]])
        ->and($samples[0]->pm2_error)->toBeNull()
        ->and($samples[1]->pm2_processes)->toBeNull()
        ->and(mb_strlen($samples[1]->pm2_error))->toBe(255);
});

it('rejects a malformed pm2 signal before recording a tick', function (string $field, mixed $value) {
    [$monitor, $token] = platformMonitor();
    $payload = agentFixture('tick');
    data_set($payload, $field, $value);

    $this->withToken($token)->postJson(route('api.agent.tick'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($monitor->appHealthSamples()->count())->toBe(0);
})->with([
    'processes not a list' => ['signals.pm2.processes', ['a' => 1]],
    'missing name' => ['signals.pm2.processes.0.name', null],
    'id not an integer' => ['signals.pm2.processes.0.id', 'zero'],
    'missing status' => ['signals.pm2.processes.0.status', null],
    'negative restarts' => ['signals.pm2.processes.0.restarts', -1],
    'negative uptime' => ['signals.pm2.processes.0.uptime_seconds', -1],
    'negative memory' => ['signals.pm2.processes.0.memory_bytes', -1],
]);

it('rejects more than a hundred pm2 processes', function () {
    [$monitor, $token] = platformMonitor();
    $payload = agentFixture('tick');
    $process = $payload['signals']['pm2']['processes'][0];
    $payload['signals']['pm2']['processes'] = array_map(fn (int $id) => [...$process, 'id' => $id, 'name' => "worker-{$id}"], range(0, 100));

    $this->withToken($token)->postJson(route('api.agent.tick'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('signals.pm2.processes');
});
```

- [ ] **Step 3: Run to see them fail**

Run: `php artisan test --compact tests/Feature/Controllers/Api/AgentPlatformSignalsTest.php`
Expected: FAIL (unknown column `pm2_processes`, no validation errors).

- [ ] **Step 4: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_health_samples', function (Blueprint $table): void {
            $table->jsonb('pm2_processes')->nullable();
            $table->string('pm2_error')->nullable();
        });

        Schema::create('agent_pm2_processes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('server');
            $table->string('name');
            $table->string('status', 32)->nullable();
            $table->unsignedInteger('restarts')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('ignored_at')->nullable();
            $table->unique(['monitor_id', 'server', 'name']);
        });
    }
};
```

- [ ] **Step 5: TickRequest rules** (after `signals.app.debug`)

```php
            'signals.pm2' => ['sometimes', 'array'],
            'signals.pm2.processes' => ['present_with:signals.pm2', 'nullable', 'array', 'list', 'max:100'],
            'signals.pm2.processes.*' => ['array'],
            'signals.pm2.processes.*.id' => ['required', 'integer', 'min:0'],
            'signals.pm2.processes.*.name' => ['required', 'string', 'max:255'],
            'signals.pm2.processes.*.status' => ['required', 'string', 'max:32'],
            'signals.pm2.processes.*.restarts' => ['required', 'integer', 'min:0'],
            'signals.pm2.processes.*.uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'signals.pm2.processes.*.memory_bytes' => ['nullable', 'integer', 'min:0'],
            'signals.pm2.error' => ['nullable', 'string'],
```

And an accessor after `queue()`:

```php
    /**
     * The pm2 signal, null from an Agent or server that does not send it.
     *
     * @return ?array{processes: ?list<array{id: int, name: string, status: string, restarts: int, uptime_seconds: ?int, memory_bytes: ?int}>, error?: ?string}
     */
    public function pm2(): ?array
    {
        return $this->validated('signals.pm2');
    }
```

- [ ] **Step 6: Model and factory**

`AppHealthSample`: add `'pm2_processes', 'pm2_error'` to `$fillable` and `'pm2_processes' => 'array'` to `casts()`. In `AppHealthSampleFactory` add:

```php
    /** @param list<array{id: int, name: string, status: string, restarts?: int, uptime_seconds?: ?int, memory_bytes?: ?int}> $processes */
    public function pm2(array $processes): static
    {
        return $this->state(['pm2_processes' => array_map(fn (array $process): array => [
            'restarts' => 0, 'uptime_seconds' => 60, 'memory_bytes' => 1048576, ...$process,
        ], $processes)]);
    }
```

- [ ] **Step 7: Store in `RecordAppHealthSample`**

Change `SIGNALS` to `['database', 'cache', 'disk', 'failed_jobs', 'load', 'memory', 'app', 'pm2']`, and add to the `create([...])`:

```php
            'pm2_processes' => $signals['pm2']['processes'] ?? null,
            'pm2_error' => $this->error($signals['pm2'] ?? []),
```

Update the class docblock sentence "The queue backlog is sampled here; the canary is tracked separately." to "The queue backlog and the PM2 processes are sampled here; the canary and the learned PM2 processes are kept separately."

- [ ] **Step 8: Migrate and run**

Run: `php artisan migrate && php artisan test --compact tests/Feature/Controllers/Api`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add tests/Fixtures/agent/schema-1/tick.json database/migrations/2026_10_15_120000_add_pm2_to_app_health.php app/Http/Requests/Api/Agent/TickRequest.php app/Models/AppHealthSample.php database/factories/AppHealthSampleFactory.php app/Actions/Agent/RecordAppHealthSample.php tests/Feature/Controllers/Api/AgentPlatformSignalsTest.php
git commit -m "feat: keep the pm2 processes of a tick"
```

### Task B2: Learn the processes of every server

**Files:**
- Create: `app/Models/AgentPm2Process.php`
- Create: `database/factories/AgentPm2ProcessFactory.php`
- Modify: `app/Models/Monitor.php` (relation)
- Create: `app/Actions/Agent/RecordPm2Processes.php`
- Modify: `app/Http/Controllers/Api/AgentTicksController.php`
- Test: `tests/Feature/Controllers/Api/AgentPm2ProcessesTest.php`

**Interfaces:**
- Consumes: `TickRequest::pm2()`, table `agent_pm2_processes`.
- Produces: `AgentPm2Process` (fillable `monitor_id, server, name, status, restarts, first_seen_at, last_seen_at, ignored_at`; `isIgnored(): bool`; `scopeWatched(Builder)`; `auditParent(): ?Model`), `Monitor::agentPm2Processes(): HasMany`, `RecordPm2Processes::execute(Monitor $monitor, ?string $server, ?array $pm2): void`, `RecordPm2Processes::statusOf(iterable $instances): string`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Controllers/Api/AgentPm2ProcessesTest.php`)

```php
<?php

use App\Actions\AgentKeys\CreateAgentKey;
use App\Enums\Capability;
use App\Models\AgentPm2Process;
use App\Models\Monitor;

function pm2Monitor(array $capabilities = [Capability::APP_HEALTH]): array
{
    $factory = Monitor::factory();

    foreach ($capabilities as $capability) {
        $factory = $factory->withCapability($capability);
    }

    $monitor = $factory->create();

    return [$monitor, app(CreateAgentKey::class)->execute($monitor, null)];
}

function pm2Tick(string $token, ?array $processes, string $server = 'web-01', ?string $error = null): void
{
    test()->withToken($token)->postJson(route('api.agent.tick'), [
        ...agentFixture('tick'),
        'server' => $server,
        'signals' => ['pm2' => ['processes' => $processes, 'error' => $error]],
    ])->assertOk();
}

function pm2Process(int $id, string $name, string $status = 'online', int $restarts = 0): array
{
    return ['id' => $id, 'name' => $name, 'status' => $status, 'restarts' => $restarts, 'uptime_seconds' => $status === 'online' ? 60 : null, 'memory_bytes' => 1048576];
}

it('learns every process name per server', function () {
    $this->freezeSecond();
    [$monitor, $token] = pm2Monitor();

    pm2Tick($token, [pm2Process(0, 'queue-worker'), pm2Process(1, 'queue-pusher', 'stopped', 4)]);
    pm2Tick($token, [pm2Process(0, 'queue-worker')], 'web-02');

    expect($monitor->agentPm2Processes()->orderBy('server')->orderBy('name')->get()->map->only('server', 'name', 'status', 'restarts')->all())->toBe([
        ['server' => 'web-01', 'name' => 'queue-pusher', 'status' => 'stopped', 'restarts' => 4],
        ['server' => 'web-01', 'name' => 'queue-worker', 'status' => 'online', 'restarts' => 0],
        ['server' => 'web-02', 'name' => 'queue-worker', 'status' => 'online', 'restarts' => 0],
    ]);
});

it('folds cluster instances into one name that is down when one instance is', function () {
    [$monitor, $token] = pm2Monitor();

    pm2Tick($token, [pm2Process(0, 'api', restarts: 1), pm2Process(1, 'api', 'errored', 2), pm2Process(2, 'api', restarts: 3)]);

    expect($monitor->agentPm2Processes()->sole())->name->toBe('api')->status->toBe('errored')->restarts->toBe(6);
});

it('keeps when a name was first and last seen, and remembers a name that is no longer listed', function () {
    $this->freezeSecond();
    [$monitor, $token] = pm2Monitor();
    $firstSeen = now();

    pm2Tick($token, [pm2Process(0, 'queue-worker'), pm2Process(1, 'queue-pusher')]);
    $this->travel(5)->minutes();
    pm2Tick($token, [pm2Process(0, 'queue-worker')]);

    $worker = $monitor->agentPm2Processes()->where('name', 'queue-worker')->sole();
    $pusher = $monitor->agentPm2Processes()->where('name', 'queue-pusher')->sole();
    expect($worker->first_seen_at->equalTo($firstSeen))->toBeTrue()
        ->and($worker->last_seen_at->equalTo(now()))->toBeTrue()
        ->and($pusher->last_seen_at->equalTo($firstSeen))->toBeTrue();
});

it('learns nothing from a list that could not be read, an empty list or a tick without pm2', function () {
    [$monitor, $token] = pm2Monitor();

    pm2Tick($token, null, error: 'pm2 jlist did not answer within 3 seconds');
    pm2Tick($token, []);
    $this->withToken($token)->postJson(route('api.agent.tick'), agentFixture('tick-legacy'))->assertOk();

    expect($monitor->agentPm2Processes()->count())->toBe(0);
});

it('learns while App health is off or the Monitor is paused', function () {
    [$monitor, $token] = pm2Monitor(capabilities: []);
    $monitor->update(['is_active' => false]);

    pm2Tick($token, [pm2Process(0, 'queue-worker')]);

    expect($monitor->agentPm2Processes()->count())->toBe(1);
});

it('keeps a name ignored when it is listed again', function () {
    [$monitor, $token] = pm2Monitor();
    AgentPm2Process::factory()->for($monitor)->create(['server' => 'web-01', 'name' => 'other-site', 'ignored_at' => now()]);

    pm2Tick($token, [pm2Process(0, 'other-site')]);

    expect($monitor->agentPm2Processes()->sole()->isIgnored())->toBeTrue();
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/Controllers/Api/AgentPm2ProcessesTest.php`
Expected: FAIL, `Class "App\Models\AgentPm2Process" not found`.

- [ ] **Step 3: Model `app/Models/AgentPm2Process.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\StoresDatesInUtc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PM2 process name one server of the app listed at least once. Kept after
 * it leaves the list, since a process that disappears (a reboot without
 * `pm2 startup`, a deleted process) is exactly what a missing name means.
 * Known by its name: PM2 hands out a new id after a delete and start, and
 * the instances of one app in cluster mode share a name.
 *
 * An ignored name is not judged, whether it is listed again or not: a process
 * removed on purpose, or one of another site on a shared daemon.
 */
class AgentPm2Process extends Model
{
    use HasFactory;
    use StoresDatesInUtc;

    public $timestamps = false;

    protected $fillable = ['monitor_id', 'server', 'name', 'status', 'restarts', 'first_seen_at', 'last_seen_at', 'ignored_at'];

    protected function casts(): array
    {
        return [
            'restarts' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ignored_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** @param Builder<$this> $query */
    public function scopeWatched(Builder $query): void
    {
        $query->whereNull('ignored_at');
    }

    public function isIgnored(): bool
    {
        return $this->ignored_at !== null;
    }

    public function auditParent(): ?Model
    {
        return Monitor::query()->withTrashed()->find($this->monitor_id);
    }
}
```

Factory `database/factories/AgentPm2ProcessFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AgentPm2Process;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgentPm2Process> */
class AgentPm2ProcessFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'server' => 'web-01',
            'name' => 'queue-worker',
            'status' => 'online',
            'restarts' => 0,
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'ignored_at' => null,
        ];
    }
}
```

In `Monitor`, next to `appHealthSamples()`:

```php
    /** @return HasMany<AgentPm2Process, $this> */
    public function agentPm2Processes(): HasMany
    {
        return $this->hasMany(AgentPm2Process::class);
    }
```

- [ ] **Step 4: Action `app/Actions/Agent/RecordPm2Processes.php`**

```php
<?php

namespace App\Actions\Agent;

use App\Models\AgentPm2Process;
use App\Models\Monitor;

/**
 * Learns the PM2 process names a tick lists for its server, so a name that
 * later goes missing can be told from one that never ran. Runs whatever the
 * Monitor's state: a Monitor whose App health is switched on later already
 * knows its processes. A list that could not be read teaches nothing, and a
 * name that is ignored stays ignored.
 *
 * One upsert per tick. `status` is the name's worst instance: `online` only
 * when every instance is. `restarts` adds the instances up.
 */
class RecordPm2Processes
{
    /** @param ?array{processes?: ?list<array{name: string, status: string, restarts: int}>} $pm2 */
    public function execute(Monitor $monitor, ?string $server, ?array $pm2): void
    {
        $processes = $pm2['processes'] ?? null;

        if ($server === null || ! is_array($processes) || $processes === []) {
            return;
        }

        $now = now();

        $rows = collect($processes)
            ->groupBy('name')
            ->map(fn ($instances, string $name): array => [
                'monitor_id' => $monitor->id,
                'server' => $server,
                'name' => $name,
                'status' => self::statusOf($instances),
                'restarts' => (int) $instances->sum('restarts'),
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ])
            ->values()
            ->all();

        AgentPm2Process::query()->upsert($rows, ['monitor_id', 'server', 'name'], ['status', 'restarts', 'last_seen_at']);
    }

    /** @param iterable<array{status: string}> $instances */
    public static function statusOf(iterable $instances): string
    {
        foreach ($instances as $instance) {
            if ($instance['status'] !== 'online') {
                return $instance['status'];
            }
        }

        return 'online';
    }
}
```

Note: `upsert` bypasses the model's `StoresDatesInUtc`; pass `$now->utc()` if the test on `first_seen_at` fails because of the app timezone.

- [ ] **Step 5: Wire into `AgentTicksController::store`**

Inject `RecordPm2Processes $recordPm2Processes` and call it before `$recordAppHealthSample->execute(...)`:

```php
        $recordPm2Processes->execute($monitor, $request->agentServer(), $request->pm2());
```

- [ ] **Step 6: Run**

Run: `php artisan test --compact tests/Feature/Controllers/Api`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Models/AgentPm2Process.php database/factories/AgentPm2ProcessFactory.php app/Models/Monitor.php app/Actions/Agent/RecordPm2Processes.php app/Http/Controllers/Api/AgentTicksController.php tests/Feature/Controllers/Api/AgentPm2ProcessesTest.php
git commit -m "feat: learn the pm2 processes of every server"
```

### Task B3: PM2 process down Incident

**Files:**
- Modify: `app/Enums/IncidentCause.php`
- Create: `app/Actions/Agent/FindDownPm2Processes.php`
- Create: `app/Actions/Agent/ResolvePm2Incident.php`
- Create: `app/Actions/Agent/TrackPm2Processes.php`
- Modify: `app/Actions/Agent/RecordAppHealthSample.php` (`judge`)
- Modify: `app/Livewire/Incidents/Detail.php` (eager load arm)
- Modify: `app/Actions/Incidents/Vitals/AppHealthVitals.php`
- Test: `tests/Feature/Controllers/Api/AgentPm2IncidentsTest.php`

**Interfaces:**
- Consumes: `AgentPm2Process::watched()`, `RaiseAppHealthIncident::execute(Monitor, string $signal, IncidentCause, ?string $server, array $details, ?string $error = null)`, `ResolveAppHealthIncident::execute(Monitor, IncidentCause, string $resolution)`, `MonitorAppHealthConfig::unreachableAfterSamples()`.
- Produces: `IncidentCause::AUTO_APP_PM2`; `FindDownPm2Processes::execute(AppHealthSample $sample, ?Collection $known = null): ?array` returning `array<string, array{status: string, last_seen_at: ?CarbonInterface}>` keyed by name (status `missing` for a name not listed), or `null` when the sample has no list; `ResolvePm2Incident::execute(Monitor $monitor): void`; `TrackPm2Processes::execute(Monitor, AppHealthSample, MonitorAppHealthConfig): void`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Controllers/Api/AgentPm2IncidentsTest.php`)

```php
<?php

use App\Actions\AgentKeys\CreateAgentKey;
use App\Actions\Incidents\Vitals\AppHealthVitals;
use App\Enums\Capability;
use App\Enums\IncidentCause;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Notifications\AppHealthFailingNotification;
use App\Notifications\AppHealthRecoveredNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->freezeSecond();
});

function pm2IncidentMonitor(): array
{
    $monitor = Monitor::factory()->withCapability(Capability::APP_HEALTH)->create();
    $monitor->appHealthConfig->update(['unreachable_after_samples' => 2]);
    $channel = NotificationChannel::factory()->email()->create([
        'organisation_id' => $monitor->organisation_id,
        'config' => ['addresses' => ['app@example.com']],
    ]);
    $monitor->capabilityNotificationChannels()->attach($channel, ['capability' => Capability::APP_HEALTH->value]);

    return [$monitor, app(CreateAgentKey::class)->execute($monitor, null)];
}

function pm2IncidentTick(string $token, ?array $processes, string $server = 'web-01'): void
{
    test()->withToken($token)->postJson(route('api.agent.tick'), [
        ...agentFixture('tick'), 'server' => $server,
        'signals' => ['pm2' => ['processes' => $processes, 'error' => $processes === null ? 'pm2 jlist printed no JSON list' : null]],
    ])->assertOk();
    test()->travel(1)->minute();
}

function pm2Running(array $statuses): array
{
    $processes = [];

    foreach ($statuses as $name => $status) {
        $processes[] = ['id' => count($processes), 'name' => $name, 'status' => $status, 'restarts' => 0, 'uptime_seconds' => null, 'memory_bytes' => null];
    }

    return $processes;
}

it('opens one Incident once a process stays down for the configured ticks, and resolves it when it runs again', function () {
    [$monitor, $token] = pm2IncidentMonitor();

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online', 'queue-pusher' => 'online']));
    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online', 'queue-pusher' => 'errored']));
    expect($monitor->incidents()->count())->toBe(0);

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online', 'queue-pusher' => 'errored']));
    $incident = $monitor->incidents()->active()->sole();
    expect($incident->cause)->toBe(IncidentCause::AUTO_APP_PM2)
        ->and($incident->metadata['server'])->toBe('web-01')
        ->and($incident->metadata['health_details'])->toContain(['label' => 'queue-pusher', 'value' => 'errored']);
    Notification::assertSentOnDemandTimes(AppHealthFailingNotification::class, 1);
    expect(app(AppHealthVitals::class)->execute($monitor->fresh(), IncidentCause::AUTO_APP_PM2)['healthy'])->toBeFalse();

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online', 'queue-pusher' => 'errored']));
    Notification::assertSentOnDemandTimes(AppHealthFailingNotification::class, 1);

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online', 'queue-pusher' => 'online']));
    expect($monitor->incidents()->active()->count())->toBe(0);
    Notification::assertSentOnDemandTimes(AppHealthRecoveredNotification::class, 1);
    expect(app(AppHealthVitals::class)->execute($monitor->fresh(), IncidentCause::AUTO_APP_PM2)['healthy'])->toBeTrue();
});

it('treats a known process missing from the list as down, also when no daemon runs', function () {
    [$monitor, $token] = pm2IncidentMonitor();

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online']));
    pm2IncidentTick($token, []);
    pm2IncidentTick($token, []);

    $incident = $monitor->incidents()->active()->sole();
    expect($incident->metadata['health_details'])->toContain(['label' => 'queue-worker', 'value' => 'missing']);
});

it('marks a cluster name down when one of its instances is', function () {
    [$monitor, $token] = pm2IncidentMonitor();
    $cluster = fn (string $second) => [
        ['id' => 0, 'name' => 'api', 'status' => 'online', 'restarts' => 0, 'uptime_seconds' => 60, 'memory_bytes' => null],
        ['id' => 1, 'name' => 'api', 'status' => $second, 'restarts' => 9, 'uptime_seconds' => null, 'memory_bytes' => null],
    ];

    pm2IncidentTick($token, $cluster('errored'));
    pm2IncidentTick($token, $cluster('errored'));

    expect($monitor->incidents()->active()->sole()->cause)->toBe(IncidentCause::AUTO_APP_PM2);
});

it('breaks a streak on a list that could not be read', function () {
    [$monitor, $token] = pm2IncidentMonitor();

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'stopped']));
    pm2IncidentTick($token, null);
    pm2IncidentTick($token, pm2Running(['queue-worker' => 'stopped']));

    expect($monitor->incidents()->count())->toBe(0);
});

it('stays open while another server still has a process down, and ignores a server without pm2', function () {
    [$monitor, $token] = pm2IncidentMonitor();

    foreach (['web-01', 'worker-01'] as $server) {
        pm2IncidentTick($token, pm2Running(['queue-worker' => 'stopped']), $server);
        pm2IncidentTick($token, pm2Running(['queue-worker' => 'stopped']), $server);
    }
    expect($monitor->incidents()->active()->count())->toBe(1);

    test()->withToken($token)->postJson(route('api.agent.tick'), [...agentFixture('tick'), 'server' => 'web-02', 'signals' => ['app' => ['maintenance' => false, 'debug' => false]]])->assertOk();
    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online']));
    expect($monitor->incidents()->active()->count())->toBe(1);

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'online']), 'worker-01');
    expect($monitor->incidents()->active()->count())->toBe(0);
});

it('does not judge ignored processes', function () {
    [$monitor, $token] = pm2IncidentMonitor();

    pm2IncidentTick($token, pm2Running(['other-site' => 'online']));
    $monitor->agentPm2Processes()->update(['ignored_at' => now()]);
    pm2IncidentTick($token, []);
    pm2IncidentTick($token, pm2Running(['other-site' => 'stopped']));
    pm2IncidentTick($token, pm2Running(['other-site' => 'stopped']));

    expect($monitor->incidents()->count())->toBe(0);
});

it('keeps a paused Monitor quiet', function () {
    [$monitor, $token] = pm2IncidentMonitor();
    $monitor->update(['is_active' => false]);

    pm2IncidentTick($token, pm2Running(['queue-worker' => 'stopped']));
    pm2IncidentTick($token, pm2Running(['queue-worker' => 'stopped']));

    expect($monitor->incidents()->count())->toBe(0);
});
```

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/Controllers/Api/AgentPm2IncidentsTest.php`
Expected: FAIL, `Undefined constant App\Enums\IncidentCause::AUTO_APP_PM2`.

- [ ] **Step 3: `IncidentCause`**

After `AUTO_APP_QUEUE_BACKLOG`:

```php
    /** A server of the app listed a known PM2 process as not online, or not at all, for `unreachable_after_samples` ticks in a row. */
    case AUTO_APP_PM2 = 'auto_app_pm2';
```

Add an arm in every `match`:
- `recoveryTitle`: `self::AUTO_APP_PM2 => "Every PM2 process on $monitorName runs again",`
- `capability`: add `self::AUTO_APP_PM2,` to the list ending in `=> Capability::APP_HEALTH`.
- `workCategory`: add `self::AUTO_APP_PM2,` to the list ending in `=> WorkCategory::APP_HEALTH`.
- `label`: `self::AUTO_APP_PM2 => 'PM2 process down',`
- `title`: `self::AUTO_APP_PM2 => "A PM2 process stopped on $monitorName",`

In `app/Livewire/Incidents/Detail.php` `mount`, add `IncidentCause::AUTO_APP_PM2,` to the App health arm that loads `['appHealthConfig', 'organisation']`.

Then `grep -rn "AUTO_APP_QUEUE_BACKLOG" app resources` and add `AUTO_APP_PM2` next to every other hit that is an exhaustive list (skip `FindingType`, which is Finding escalation only).

- [ ] **Step 4: `app/Actions/Agent/FindDownPm2Processes.php`**

```php
<?php

namespace App\Actions\Agent;

use App\Models\AgentPm2Process;
use App\Models\AppHealthSample;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The watched PM2 processes a sample's server had down: listed with a status
 * other than `online` for any instance, or known and missing from the list.
 * Null when the sample carries no list, which is unknown rather than healthy.
 * Judged against the processes known now, so an ignored name stops counting
 * for older samples too.
 */
class FindDownPm2Processes
{
    /**
     * @param  ?Collection<int, AgentPm2Process>  $known  The server's watched processes, when the caller already has them
     * @return ?array<string, array{status: string, last_seen_at: ?CarbonInterface}>
     */
    public function execute(AppHealthSample $sample, ?Collection $known = null): ?array
    {
        if ($sample->pm2_processes === null) {
            return null;
        }

        $known ??= AgentPm2Process::query()
            ->where('monitor_id', $sample->monitor_id)
            ->where('server', $sample->server)
            ->watched()
            ->get();

        $listed = collect($sample->pm2_processes)->groupBy('name');
        $down = [];

        foreach ($known as $process) {
            $instances = $listed->get($process->name);
            $status = $instances === null ? 'missing' : RecordPm2Processes::statusOf($instances);

            if ($status !== 'online') {
                $down[$process->name] = ['status' => $status, 'last_seen_at' => $process->last_seen_at];
            }
        }

        ksort($down);

        return $down;
    }
}
```

- [ ] **Step 5: `app/Actions/Agent/ResolvePm2Incident.php`**

```php
<?php

namespace App\Actions\Agent;

use App\Enums\IncidentCause;
use App\Models\AppHealthSample;
use App\Models\Monitor;

/**
 * Closes the PM2 Incident once the newest current sample of every server
 * that reports PM2 has no watched process down. A server without a list says
 * nothing either way. Asked by every tick and when a process is ignored.
 */
class ResolvePm2Incident
{
    public function __construct(
        private FindDownPm2Processes $findDown,
        private ResolveAppHealthIncident $resolveIncident,
    ) {}

    public function execute(Monitor $monitor): void
    {
        if (! $monitor->incidents()->active()->forCause(IncidentCause::AUTO_APP_PM2)->exists()) {
            return;
        }

        $stillDown = AppHealthSample::query()
            ->where('monitor_id', $monitor->id)
            ->current()
            ->latestPerServer()
            ->get()
            ->contains(fn (AppHealthSample $latest): bool => ($this->findDown->execute($latest) ?? []) !== []);

        if ($stillDown) {
            return;
        }

        $this->resolveIncident->execute($monitor, IncidentCause::AUTO_APP_PM2, 'Every PM2 process runs again');
    }
}
```

- [ ] **Step 6: `app/Actions/Agent/TrackPm2Processes.php`**

```php
<?php

namespace App\Actions\Agent;

use App\Enums\IncidentCause;
use App\Models\AgentPm2Process;
use App\Models\AppHealthSample;
use App\Models\Monitor;
use App\Models\MonitorAppHealthConfig;

/**
 * Keeps the PM2 Incident in step with a new sample. A server that had a
 * watched process down `unreachable_after_samples` ticks in a row opens it,
 * through RaiseAppHealthIncident, so a process that restarts within a tick
 * does not page. A sample without a list is unknown and breaks the streak.
 */
class TrackPm2Processes
{
    public function __construct(
        private FindDownPm2Processes $findDown,
        private RaiseAppHealthIncident $raiseIncident,
        private ResolvePm2Incident $resolveIncident,
    ) {}

    public function execute(Monitor $monitor, AppHealthSample $sample, MonitorAppHealthConfig $config): void
    {
        $known = AgentPm2Process::query()->where('monitor_id', $monitor->id)->where('server', $sample->server)->watched()->get();
        $down = $this->findDown->execute($sample, $known);

        if ($down === null) {
            return;
        }

        if ($down === []) {
            $this->resolveIncident->execute($monitor);

            return;
        }

        $required = $config->unreachableAfterSamples();
        $latest = AppHealthSample::query()
            ->where('monitor_id', $sample->monitor_id)
            ->where('server', $sample->server)
            ->orderByDesc('sampled_at')
            ->orderByDesc('id')
            ->limit($required)
            ->get();

        if ($latest->count() < $required || ! $latest->every(fn (AppHealthSample $reading): bool => ($this->findDown->execute($reading, $known) ?? []) !== [])) {
            return;
        }

        $this->raiseIncident->execute($monitor, 'pm2', IncidentCause::AUTO_APP_PM2, $sample->server, [
            ['label' => 'Server', 'value' => $sample->server],
            ...collect($down)->map(fn (array $process, string $name): array => ['label' => $name, 'value' => $process['status']])->values()->all(),
        ]);
    }
}
```

- [ ] **Step 7: Judge from `RecordAppHealthSample`**

Inject `private TrackPm2Processes $trackPm2Processes,` and call `$this->trackPm2Processes->execute($monitor, $sample, $config);` last in `judge()`.

- [ ] **Step 8: Vitals**

In `AppHealthVitals::execute` add an arm before `default`:

```php
            IncidentCause::AUTO_APP_PM2 => ['PM2', $this->pm2($samples), $this->pm2Healthy($samples), $noSample],
```

Inject `FindDownPm2Processes` through a constructor (`public function __construct(private FindDownPm2Processes $findDown) {}`) and add:

```php
    /**
     * @param  Collection<int, AppHealthSample>  $samples
     * @return list<array{label: string, value: string, hint: ?string, tone: ?string}>
     */
    private function pm2(Collection $samples): array
    {
        $rows = [];

        foreach ($samples as $sample) {
            $down = $this->findDown->execute($sample);

            if ($down === null) {
                continue;
            }

            if ($down === []) {
                $rows[] = ['label' => 'PM2', 'value' => 'Every process online', 'hint' => $sample->server, 'tone' => null];
            }

            foreach ($down as $name => $process) {
                $rows[] = ['label' => $name, 'value' => $process['status'], 'hint' => $sample->server, 'tone' => 'bad'];
            }
        }

        return $rows;
    }

    /** @param  Collection<int, AppHealthSample>  $samples */
    private function pm2Healthy(Collection $samples): ?bool
    {
        $readings = $samples->map(fn (AppHealthSample $sample): ?array => $this->findDown->execute($sample))->filter(fn (?array $down): bool => $down !== null);

        return $readings->isEmpty() ? null : $readings->every(fn (array $down): bool => $down === []);
    }
```

- [ ] **Step 9: Run**

Run: `php artisan test --compact tests/Feature/Controllers/Api tests/Feature/Actions/Agent tests/Unit`
Expected: PASS. Then the full suite `php artisan test --compact --parallel` to catch exhaustive `match` lists over `IncidentCause`.

- [ ] **Step 10: Commit**

```bash
git add app/Enums/IncidentCause.php app/Actions/Agent/FindDownPm2Processes.php app/Actions/Agent/ResolvePm2Incident.php app/Actions/Agent/TrackPm2Processes.php app/Actions/Agent/RecordAppHealthSample.php app/Livewire/Incidents/Detail.php app/Actions/Incidents/Vitals/AppHealthVitals.php tests/Feature/Controllers/Api/AgentPm2IncidentsTest.php
git commit -m "feat: incident when a pm2 process stops"
```

### Task B4: PM2 block on the App health tab with Stop watching

**Files:**
- Modify: `app/Enums/AuditEvent.php`
- Create: `app/Actions/Agent/IgnorePm2Process.php`
- Create: `app/Actions/Agent/WatchPm2Process.php`
- Modify: `app/Livewire/Monitors/AppHealth.php`
- Modify: `app/Actions/Monitors/BuildAppHealthPanel.php`
- Create: `resources/views/components/app-health/pm2.blade.php`
- Modify: `resources/views/livewire/monitors/app-health.blade.php`
- Test: `tests/Feature/Livewire/MonitorAppHealthPm2Test.php`

**Interfaces:**
- Consumes: `AgentPm2Process`, `ResolvePm2Incident::execute(Monitor)`, `RecordAuditEntry::execute(AuditEvent, ?Model $parent, properties:, causer:)`.
- Produces: `AuditEvent::PM2_PROCESS_IGNORED`, `AuditEvent::PM2_PROCESS_WATCHED`; `IgnorePm2Process::execute(AgentPm2Process, ?User): AgentPm2Process`; `WatchPm2Process::execute(AgentPm2Process, ?User): AgentPm2Process`; Livewire `AppHealth::ignorePm2Process(int $processId)` and `watchPm2Process(int $processId)`; panel key `pm2` as `list<array{process: AgentPm2Process, state: string, uptimeSeconds: ?int, memoryBytes: ?int}>`.

- [ ] **Step 1: Write the failing tests** (`tests/Feature/Livewire/MonitorAppHealthPm2Test.php`)

```php
<?php

use App\Enums\AuditEvent;
use App\Enums\Capability;
use App\Enums\IncidentCause;
use App\Livewire\Monitors\AppHealth;
use App\Models\AgentPm2Process;
use App\Models\AppHealthSample;
use App\Models\Incident;
use App\Models\Monitor;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(fn () => test()->travelTo(CarbonImmutable::parse('2026-06-15 12:30:00')));

it('lists the learned processes of two monitors per server without lazy loading', function () {
    ['organisation' => $organisation] = actingAsOrganisationMember();
    [$shop, $portal] = Monitor::factory()->for($organisation)->withCapability(Capability::APP_HEALTH)->count(2)->create(['agent_last_tick_at' => now()])->all();

    foreach ([$shop, $portal] as $monitor) {
        AppHealthSample::factory()->for($monitor)->pm2([
            ['id' => 0, 'name' => "{$monitor->id}-worker", 'status' => 'online', 'uptime_seconds' => 7200],
        ])->create(['sampled_at' => now()->subMinute()]);
        AgentPm2Process::factory()->for($monitor)->create(['name' => "{$monitor->id}-worker"]);
        AgentPm2Process::factory()->for($monitor)->create(['name' => "{$monitor->id}-pusher", 'status' => 'online', 'last_seen_at' => now()->subHour()]);
    }

    $this->get(route('monitors.app-health', $shop))
        ->assertOk()
        ->assertSee(['PM2', "{$shop->id}-worker", "{$shop->id}-pusher", 'Missing', 'Stop watching'])
        ->assertDontSee("{$portal->id}-worker");
});

it('stops and starts watching a process, with an audit entry each time', function () {
    ['organisation' => $organisation, 'user' => $user] = actingAsOrganisationMember();
    $monitor = Monitor::factory()->for($organisation)->withCapability(Capability::APP_HEALTH)->create();
    $process = AgentPm2Process::factory()->for($monitor)->create();

    Livewire::test(AppHealth::class, ['monitorId' => $monitor->id])->call('ignorePm2Process', $process->id);
    expect($process->fresh()->isIgnored())->toBeTrue();

    Livewire::test(AppHealth::class, ['monitorId' => $monitor->id])->call('watchPm2Process', $process->id)->assertSee('Stop watching');
    expect($process->fresh()->isIgnored())->toBeFalse();

    expect(activity()->getActivityModel()::query()->whereIn('event', [AuditEvent::PM2_PROCESS_IGNORED->value, AuditEvent::PM2_PROCESS_WATCHED->value])->count())->toBe(2);
});

it('resolves the PM2 Incident at once when its only down process is ignored', function () {
    ['organisation' => $organisation] = actingAsOrganisationMember();
    $monitor = Monitor::factory()->for($organisation)->withCapability(Capability::APP_HEALTH)->create();
    AppHealthSample::factory()->for($monitor)->pm2([])->create(['sampled_at' => now()->subMinute()]);
    $process = AgentPm2Process::factory()->for($monitor)->create(['name' => 'old-worker']);
    Incident::factory()->for($monitor)->create(['cause' => IncidentCause::AUTO_APP_PM2]);

    Livewire::test(AppHealth::class, ['monitorId' => $monitor->id])->call('ignorePm2Process', $process->id);

    expect($monitor->incidents()->active()->count())->toBe(0);
});

it('does not let a member without update rights stop watching, nor reach another organisation', function () {
    ['organisation' => $organisation] = actingAsOrganisationMember(role: 'viewer');
    $monitor = Monitor::factory()->for($organisation)->withCapability(Capability::APP_HEALTH)->create();
    $process = AgentPm2Process::factory()->for($monitor)->create();
    $foreign = AgentPm2Process::factory()->create();

    Livewire::test(AppHealth::class, ['monitorId' => $monitor->id])->call('ignorePm2Process', $process->id)->assertForbidden();
    Livewire::test(AppHealth::class, ['monitorId' => $monitor->id])->call('ignorePm2Process', $foreign->id)->assertNotFound();
});
```

Before running, check `tests/Helpers.php` for the exact `actingAsOrganisationMember` signature and role name of a read only member, `IncidentFactory` for how an active App health Incident is made, and how other tests assert audit entries (`grep -rn "SCHEDULED_TASK_PAUSED" tests`). Adjust those three lines to the helpers that exist; keep the assertions.

- [ ] **Step 2: Run to see them fail**

Run: `php artisan test --compact tests/Feature/Livewire/MonitorAppHealthPm2Test.php`
Expected: FAIL (no PM2 block, unknown method `ignorePm2Process`).

- [ ] **Step 3: Audit events**

In `AuditEvent`, next to `SCHEDULED_TASK_PAUSED`, add `case PM2_PROCESS_IGNORED = 'pm2_process.ignored';` and `case PM2_PROCESS_WATCHED = 'pm2_process.watched';`, and an arm or list entry at every place `SCHEDULED_TASK_PAUSED` appears: label `'PM2 process no longer watched'` / `'PM2 process watched again'`, sentence `'stopped watching a PM2 process on'` / `'started watching a PM2 process again on'`, and the list around line 456.

- [ ] **Step 4: Actions**

`app/Actions/Agent/IgnorePm2Process.php`:

```php
<?php

namespace App\Actions\Agent;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditEvent;
use App\Models\AgentPm2Process;
use App\Models\User;

/**
 * Stops judging one PM2 process name of one server: a process removed on
 * purpose, or one of another site on a shared daemon. An open PM2 Incident
 * is judged again at once, so ignoring its only down process resolves it.
 */
class IgnorePm2Process
{
    public function __construct(
        private ResolvePm2Incident $resolvePm2Incident,
        private RecordAuditEntry $recordAuditEntry,
    ) {}

    public function execute(AgentPm2Process $process, ?User $causer): AgentPm2Process
    {
        if ($process->isIgnored()) {
            return $process;
        }

        $process->update(['ignored_at' => now()]);

        $this->resolvePm2Incident->execute($process->monitor);

        $this->recordAuditEntry->execute(
            AuditEvent::PM2_PROCESS_IGNORED,
            $process->auditParent(),
            properties: ['pm2_process' => $process->name, 'server' => $process->server],
            causer: $causer,
        );

        return $process;
    }
}
```

`app/Actions/Agent/WatchPm2Process.php`:

```php
<?php

namespace App\Actions\Agent;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditEvent;
use App\Models\AgentPm2Process;
use App\Models\User;

/** Judges an ignored PM2 process name again, from the next tick of its server. */
class WatchPm2Process
{
    public function __construct(private RecordAuditEntry $recordAuditEntry) {}

    public function execute(AgentPm2Process $process, ?User $causer): AgentPm2Process
    {
        if (! $process->isIgnored()) {
            return $process;
        }

        $process->update(['ignored_at' => null]);

        $this->recordAuditEntry->execute(
            AuditEvent::PM2_PROCESS_WATCHED,
            $process->auditParent(),
            properties: ['pm2_process' => $process->name, 'server' => $process->server],
            causer: $causer,
        );

        return $process;
    }
}
```

- [ ] **Step 5: Livewire actions** in `AppHealth`

```php
    public function ignorePm2Process(int $processId, IgnorePm2Process $ignore): void
    {
        $process = $ignore->execute($this->editableProcess($processId), auth()->user());

        $this->dispatch('toast', type: 'success', message: "{$process->name} on {$process->server} is no longer watched.");
    }

    public function watchPm2Process(int $processId, WatchPm2Process $watch): void
    {
        $process = $watch->execute($this->editableProcess($processId), auth()->user());

        $this->dispatch('toast', type: 'success', message: "{$process->name} on {$process->server} is watched again from its next tick.");
    }

    private function editableProcess(int $processId): AgentPm2Process
    {
        $monitor = $this->monitor($this->monitorId);
        $this->authorize('update', $monitor);

        return $monitor->agentPm2Processes()->findOrFail($processId)->setRelation('monitor', $monitor);
    }
```

- [ ] **Step 6: Panel data** in `BuildAppHealthPanel::execute`

Add to the returned array and its `@return` shape a `pm2` key:

```php
            'pm2' => $this->pm2($monitor, $latestSamples),
```

```php
    /**
     * Every PM2 process the Monitor learned, per server, with what the
     * server's newest sample says of it now. A known name the newest list
     * lacks reads as missing; a server whose newest sample has no list as
     * unknown.
     *
     * @param  Collection<int, AppHealthSample>  $latestSamples
     * @return list<array{process: AgentPm2Process, state: string, uptimeSeconds: ?int, memoryBytes: ?int}>
     */
    private function pm2(Monitor $monitor, Collection $latestSamples): array
    {
        $samples = $latestSamples->keyBy('server');

        return $monitor->agentPm2Processes()
            ->orderBy('server')
            ->orderBy('name')
            ->get()
            ->map(function (AgentPm2Process $process) use ($samples): array {
                $listed = $samples->get($process->server)?->pm2_processes;
                $instances = $listed === null ? null : array_values(array_filter($listed, fn (array $instance): bool => $instance['name'] === $process->name));

                return [
                    'process' => $process,
                    'state' => match (true) {
                        $listed === null => 'unknown',
                        $instances === [] => 'missing',
                        default => RecordPm2Processes::statusOf($instances),
                    },
                    'uptimeSeconds' => $instances ? min(array_map(fn (array $instance): ?int => $instance['uptime_seconds'], $instances)) : null,
                    'memoryBytes' => $instances ? array_sum(array_map(fn (array $instance): int => (int) $instance['memory_bytes'], $instances)) : null,
                ];
            })
            ->all();
    }
```

Pass `$latestSamples` as the Collection `latestSamples($monitor)` already returns; if it returns an array of server rows instead, key it by `server` from that shape.

- [ ] **Step 7: Component `resources/views/components/app-health/pm2.blade.php`**

Read `docs/claude/design-context.md` and match `x-app-health.queue`. One card under the server table:

```blade
@props(['processes'])

@use('App\Support\AppHealthReading')
@use('App\Support\Duration')

{{-- Learned from the ticks, kept after a process leaves the list: a missing name is what a reboot without `pm2 startup` looks like. --}}
<div {{ $attributes->class('rounded-lg border border-border bg-surface') }}>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[560px] text-left text-[12.5px]">
            <thead>
                <tr class="border-b border-border text-[10px] font-semibold uppercase tracking-[0.06em] text-text-muted">
                    <th class="px-4 py-2.5 font-semibold">PM2 process</th>
                    <th class="w-px whitespace-nowrap px-4 py-2.5 font-semibold">Status</th>
                    <th class="w-px whitespace-nowrap px-4 py-2.5 font-semibold">Restarts</th>
                    <th class="w-px whitespace-nowrap px-4 py-2.5 font-semibold">Memory</th>
                    <th class="w-px whitespace-nowrap px-4 py-2.5 font-semibold"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach($processes as $row)
                    @php($process = $row['process'])
                    <tr wire:key="pm2-{{ $process->id }}" @class(['border-b border-border last:border-b-0 align-top', 'opacity-60' => $process->isIgnored()])>
                        <td class="px-4 py-3">
                            <span class="block font-mono text-[12px] text-text">{{ $process->name }}</span>
                            <span class="mt-0.5 block font-mono text-[11px] text-text-muted">{{ $process->server }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            @if($process->isIgnored())
                                <span class="text-text-muted">Not watched</span>
                            @elseif($row['state'] === 'online')
                                <span class="flex items-center gap-1.5 text-text"><x-status-dot status="up" size="sm" /> Online</span>
                                @if($row['uptimeSeconds'] !== null)
                                    <span class="block text-[11px] text-text-muted">up {{ Duration::short($row['uptimeSeconds']) }}</span>
                                @endif
                            @elseif($row['state'] === 'missing')
                                <span class="flex items-center gap-1.5 font-medium text-warning-text"><x-status-dot status="warning" size="sm" /> Missing</span>
                                <span class="block text-[11px] text-text-muted">last seen <x-time :date="$process->last_seen_at" short /></span>
                            @elseif($row['state'] === 'unknown')
                                <span class="flex items-center gap-1.5 text-text-secondary"><x-status-dot status="unknown" size="sm" /> Not reported</span>
                            @else
                                <span class="flex items-center gap-1.5 font-medium text-warning-text"><x-status-dot status="warning" size="sm" /> {{ ucfirst($row['state']) }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-text">{{ $process->restarts ?? '–' }}</td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums text-text">{{ $row['memoryBytes'] === null ? '–' : AppHealthReading::bytes($row['memoryBytes']) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @can('update', $process->monitor)
                                @if($process->isIgnored())
                                    <x-button size="xs" variant="ghost" wire:click="watchPm2Process({{ $process->id }})">Watch again</x-button>
                                @else
                                    <x-button size="xs" variant="ghost" wire:click="ignorePm2Process({{ $process->id }})">Stop watching</x-button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
```

Check `resources/views/components/` for the real button component and its props (`grep -rn "wire:click=\"pauseTask" resources/views`) and use what the Scheduled tasks tab uses. `@can` needs `monitor` loaded on each process: set it in `pm2()` with `->each->setRelation('monitor', $monitor)` before `map`.

- [ ] **Step 8: Place it in `app-health.blade.php`**

Right after the closing `@endif` of the server table block, before the "Over time" section's wrapping `<div>`:

```blade
        @if($pm2 !== [])
            <x-app-health.pm2 :processes="$pm2" class="mt-3" />
        @endif
```

- [ ] **Step 9: Run**

Run: `php artisan test --compact tests/Feature/Livewire && vendor/bin/pint --dirty`
Expected: PASS.

- [ ] **Step 10: Look at it**

Open `https://pm2-processes.pingpong.kobaltdigital.nl.test` (after `herd link`) on a Monitor with seeded samples and processes, screenshot the App health tab to `.context/pm2-app-health.png`.

- [ ] **Step 11: Commit**

```bash
git add app/Enums/AuditEvent.php app/Actions/Agent/IgnorePm2Process.php app/Actions/Agent/WatchPm2Process.php app/Livewire/Monitors/AppHealth.php app/Actions/Monitors/BuildAppHealthPanel.php resources/views/components/app-health/pm2.blade.php resources/views/livewire/monitors/app-health.blade.php tests/Feature/Livewire/MonitorAppHealthPm2Test.php
git commit -m "feat: pm2 processes on the app health tab"
```

### Task B5: PingPong docs

**Files:**
- Modify: `docs/claude/actions-reference.md`
- Modify: `docs/claude/check-pipelines.md`

- [ ] **Step 1: actions-reference.md**

Add rows next to `TrackUnreachableServices` for `RecordPm2Processes`, `FindDownPm2Processes`, `TrackPm2Processes`, `ResolvePm2Incident`, `IgnorePm2Process`, `WatchPm2Process` with their signatures from the Interfaces blocks and one sentence each. Extend the `RecordAppHealthSample` row: stores `pm2_processes` and `pm2_error`, hands the sample to `TrackPm2Processes` too. Add `TrackPm2Processes` to "Used by" of `RaiseAppHealthIncident`.

- [ ] **Step 2: check-pipelines.md**

In the tick section mention `signals.pm2` (at most 100 processes) and that the controller hands it to `RecordPm2Processes` before `RecordAppHealthSample`. Add a "PM2 processes" subsection after "Queue canary": learning per server by name, missing means down, `unreachable_after_samples` streak, unknown on `processes: null`, resolve rule, Stop watching. Follow the cross reference block at the top of each file.

- [ ] **Step 3: Commit**

```bash
git add docs/claude/actions-reference.md docs/claude/check-pipelines.md
git commit -m "docs: pm2 processes in the pipeline reference"
```

---

## Part C: my.tiemliner.com (after A4 and the PingPong deploy)

### Task C1: Install the Agent, remove pm2:check

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `.env.example`
- Modify: `app/Console/Kernel.php`
- Delete: `app/Console/Commands/CheckPm2ProcessStatus.php`, `config/pm2.php`

- [ ] **Step 1:** `composer require kobaltdigital/pingpong-agent:^<released version>` (add the VCS repository first if it is not on Packagist).
- [ ] **Step 2:** In `.env.example` replace `PM2_ADMINISTRATOR_EMAIL=` with:

```
# Agent key of this app's Monitor in PingPong, one per environment
PINGPONG_KEY=
```

- [ ] **Step 3:** Remove the two `pm2:check` lines from `Kernel::schedule`, delete `CheckPm2ProcessStatus.php` and `config/pm2.php`. `grep -rn "pm2" app config routes tests lang` returns nothing that belongs to the old check; remove translation keys `PM2 Process %s is not running!` if present.
- [ ] **Step 4:** `php artisan test` passes and `php artisan schedule:list` shows `pingpong:ping` (with `PINGPONG_KEY` set locally) and no `pm2:check`.
- [ ] **Step 5:** Commit `feat: report to pingpong instead of mailing about pm2`, push, open a **draft** PR on `develop` that names the order: set `PINGPONG_KEY` on production, switch App health on for the Monitor, then merge.
