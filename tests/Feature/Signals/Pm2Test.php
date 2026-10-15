<?php

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use KobaltDigital\PingPong\Signals\Pm2;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

function pm2Signal(?string $binary = '/usr/bin/pm2', bool $daemonRuns = true): Pm2
{
    return new Pm2(new FakeHost(
        executables: $binary === null ? [] : ['pm2' => $binary],
        livePidFiles: $daemonRuns ? ['/srv/pm2/pm2.pid'] : [],
    ));
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

beforeEach(function () {
    $this->freezeSecond();
    $this->pm2Home = getenv('PM2_HOME');

    putenv('PM2_HOME=/srv/pm2');
});

afterEach(fn () => putenv($this->pm2Home === false ? 'PM2_HOME' : "PM2_HOME={$this->pm2Home}"));

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

it('finds the daemon in PM2_HOME, else in HOME', function (array $env, string $pidFile) {
    Process::fake(['*' => Process::result('[]')]);

    $signal = new Pm2(new FakeHost(executables: ['pm2' => '/usr/bin/pm2'], livePidFiles: [$pidFile]));

    expect(withEnv($env, $signal->collect(...)))->toBe(['processes' => [], 'error' => null]);

    Process::assertRanTimes(fn ($process) => $process->command === ['/usr/bin/pm2', 'jlist'], 1);
})->with([
    'PM2_HOME' => [['PM2_HOME' => '/var/pm2/', 'HOME' => '/home/forge'], '/var/pm2/pm2.pid'],
    'HOME' => [['PM2_HOME' => null, 'HOME' => '/home/forge/'], '/home/forge/.pm2/pm2.pid'],
]);

it('reports an error when it cannot tell where the daemon lives', function () {
    Process::fake();

    expect(withEnv(['PM2_HOME' => null, 'HOME' => null], pm2Signal()->collect(...)))
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

it('reads the list after notice lines that open with a bracket', function () {
    Process::fake(['*' => Process::result("[PM2][WARN] Applications queue-worker not running, starting...\n[PM2] App [queue-worker] launched\n".json_encode([jlistProcess(0, 'queue-worker')]))]);

    expect(pm2Signal()->collect()['processes'])->toHaveCount(1);
});

it('cuts a name to what PingPong stores', function () {
    Process::fake(['*' => Process::result(json_encode([jlistProcess(0, str_repeat('é', 300))]))]);

    expect(pm2Signal()->collect()['processes'][0]['name'])->toBe(str_repeat('é', Pm2::MAX_NAME_LENGTH));
});

it('leaves out entries without an id, a name or a status', function () {
    Process::fake(['*' => Process::result(json_encode([
        jlistProcess(0, 'queue-worker'),
        jlistProcess(1, 'queue-pusher', overrides: ['pm2_env' => ['status' => null]]),
        'garbage',
    ]))]);

    expect(array_column(pm2Signal()->collect()['processes'], 'name'))->toBe(['queue-worker']);
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
