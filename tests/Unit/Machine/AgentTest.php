<?php

use KobaltDigital\PingPong\Host;
use KobaltDigital\PingPong\Machine\Agent;
use KobaltDigital\PingPong\Machine\Client;
use KobaltDigital\PingPong\Machine\Config;
use KobaltDigital\PingPong\Machine\ProcessReport;
use KobaltDigital\PingPong\Machine\ProcessTable;
use KobaltDigital\PingPong\Machine\Runner;
use KobaltDigital\PingPong\Machine\State;
use KobaltDigital\PingPong\Machine\Users;
use KobaltDigital\PingPong\Machine\Vitals;

/** Keeps what the agent posts, and answers with the given status and body. */
class RecordingClient extends Client
{
    /** @var list<array{path: string, payload: array<string, mixed>}> */
    public array $posts = [];

    public function __construct(private int $status = 200, private array $body = ['status' => 'recorded'])
    {
        parent::__construct(new Config('https://pingpong.test', 'pp_machine_test', sys_get_temp_dir()), 'test');
    }

    public function post(string $path, array $payload): ?array
    {
        $this->posts[] = ['path' => $path, 'payload' => $payload];

        if ($this->status === 0) {
            $this->lastError = 'dns';

            return null;
        }

        return ['status' => $this->status, 'body' => $this->body];
    }
}

function machineAgent(RecordingClient $client, string $stateDirectory, ?string $proc = null, ?string $oomJournal = null, bool $system = false, ?string $passwd = null): Agent
{
    $runner = new Runner;
    $users = $passwd === null ? new Users : new Users($passwd, usePosix: false);
    $proc ??= fakeProc([10 => ['stat' => statLine(10, 'php', 100, 0, 100, 10)]]);

    return new Agent(
        new Config('https://pingpong.test', 'pp_machine_test', $stateDirectory, $system),
        $client,
        new State($stateDirectory),
        $oomJournal === null ? new Vitals(new Host, $runner) : new Vitals(new Host, $runner, $oomJournal),
        new ProcessTable($proc),
        new ProcessReport($users),
        $users,
    );
}

function stateDirectory(): string
{
    return sys_get_temp_dir().'/pingpong-machine-'.bin2hex(random_bytes(4));
}

it('sends who it is, the machine and its processes in one tick', function () {
    $client = new RecordingClient;

    $sent = machineAgent($client, stateDirectory())->tick(1000.0);

    $payload = $client->posts[0]['payload'];

    expect($sent)->toBeTrue()
        ->and($client->posts[0]['path'])->toBe('api/machine/tick')
        ->and($payload)->toHaveKeys(['schema', 'version', 'server', 'machine_id', 'os_name', 'kernel', 'cores', 'unix_user', 'mode', 'sent_at', 'signals', 'host', 'processes'])
        ->and($payload['sent_at'])->toBe('1970-01-01T00:16:40Z')
        ->and($payload['processes']['process_count'])->toBe(1)
        ->and($payload['processes']['users'][0]['names'])->toBe([['name' => 'php', 'count' => 1]]);
});

it('sends recent unique kernel kills only from a system installation', function () {
    $state = stateDirectory();
    mkdir($state);
    $journal = $state.'/oom-kills.jsonl';
    $client = new RecordingClient;
    $now = 1_795_000_000;
    $timestamp = ($now - 60) * 1_000_000;
    $cgroup = 'Memory cgroup out of memory: Killed process 1163265 (php) total-vm:145664kB, anon-rss:48876kB, file-rss:21388kB, shmem-rss:0kB, UID:100032 pgtables:248kB oom_score_adj:968';
    $host = 'Out of memory: Killed process 42 (worker (queue)) total-vm:145664kB, anon-rss:12kB, file-rss:8kB, shmem-rss:2kB';
    $entries = [
        [$timestamp + 20, $cgroup],
        [$timestamp + 10, $cgroup],
        [$timestamp, $host],
        [($now - 86401) * 1_000_000, $cgroup],
        [$timestamp, 'Another kernel message'],
    ];

    file_put_contents($journal, implode("\n", array_map(fn (array $entry): string => json_encode([
        '__REALTIME_TIMESTAMP' => (string) $entry[0],
        'MESSAGE' => $entry[1],
    ], JSON_THROW_ON_ERROR), $entries)));

    try {
        machineAgent($client, $state, oomJournal: $journal, system: true)->tick((float) $now);
        machineAgent($client, $state, oomJournal: $journal)->tick((float) $now);
        unlink($journal);
        machineAgent($client, $state, oomJournal: $journal, system: true)->tick((float) $now);

        expect($client->posts[0]['payload']['oom_kill_events'])->toBe([
            ['at' => $timestamp + 20, 'pid' => 1163265, 'process' => 'php', 'scope' => 'cgroup', 'uid' => 100032, 'rss_bytes' => 71_950_336],
            ['at' => $timestamp, 'pid' => 42, 'process' => 'worker (queue)', 'scope' => 'host', 'uid' => null, 'rss_bytes' => 22_528],
        ])->and($client->posts[1]['payload']['oom_kill_events'])->toBe([])
            ->and($client->posts[2]['payload']['oom_kill_events'])->toBe([]);
    } finally {
        if (is_file($journal)) {
            unlink($journal);
        }
    }
});

it('sends local account names on the first successful system tick and once an hour', function () {
    $state = stateDirectory();
    mkdir($state);
    $passwd = $state.'/passwd';
    $unsupportedName = str_repeat('x', 65);
    file_put_contents($passwd, implode("\n", [
        'root:x:0:0::/root:/bin/bash',
        'shop:x:1000:1000::/home/shop:/bin/bash',
        'backup:x:1000:1000::/home/backup:/bin/bash',
        'shop:x:1000:1000::/home/shop:/bin/bash',
        "{$unsupportedName}:x:1001:1001::/home/unsupported:/bin/bash",
    ]));

    $offline = new RecordingClient(503);
    machineAgent($offline, $state, system: true, passwd: $passwd)->tick(940.0);

    $client = new RecordingClient;
    machineAgent($client, $state, system: true, passwd: $passwd)->tick(1000.0);
    machineAgent($client, $state, system: true, passwd: $passwd)->tick(4599.0);
    machineAgent($client, $state, system: true, passwd: $passwd)->tick(4600.0);
    machineAgent($client, stateDirectory(), passwd: $passwd)->tick(4600.0);

    expect($client->posts[0]['payload']['unix_users'])->toBe(['root', 'shop', 'backup'])
        ->and($client->posts[1]['payload'])->not->toHaveKey('unix_users')
        ->and($client->posts[2]['payload']['unix_users'])->toBe(['root', 'shop', 'backup'])
        ->and($client->posts[3]['payload'])->not->toHaveKey('unix_users');
});

it('measures the CPU of the second tick against the first', function () {
    $state = stateDirectory();
    $client = new RecordingClient;

    machineAgent($client, $state, fakeProc([10 => ['stat' => statLine(10, 'php', 100, 0, 100, 10)]]))->tick(1000.0);
    machineAgent($client, $state, fakeProc([10 => ['stat' => statLine(10, 'php', 700, 0, 100, 10)]]))->tick(1060.0);

    expect($client->posts[0]['payload']['processes']['cpu_seconds_total'])->toBeNull()
        ->and($client->posts[1]['payload']['processes']['interval_seconds'])->toBe(60)
        ->and($client->posts[1]['payload']['processes']['cpu_seconds_total'])->toBe(6.0);
});

it('says so when PingPong refused the tick', function () {
    expect(machineAgent(new RecordingClient(401, ['message' => 'Unknown key']), stateDirectory())->tick())->toBeFalse();
});

it('tells PingPong in the first tick that gets through how long it could not reach it, and why', function () {
    $state = stateDirectory();

    machineAgent(new RecordingClient(0), $state)->tick(1000.0);
    machineAgent(new RecordingClient(503), $state)->tick(1060.0);

    $client = new RecordingClient;
    machineAgent($client, $state)->tick(1120.0);
    machineAgent($client, $state)->tick(1180.0);

    expect($client->posts[0]['payload']['connectivity'])->toBe([
        'unreachable_since' => '1970-01-01T00:16:40Z',
        'unreachable_until' => '1970-01-01T00:17:40Z',
        'failed_attempts' => 2,
        'last_error' => 'http_5xx',
    ])->and($client->posts[1]['payload']['connectivity'])->toBeNull();
});

it('counts a tick PingPong refused as reached', function () {
    $state = stateDirectory();

    machineAgent(new RecordingClient(422), $state)->tick(1000.0);

    $client = new RecordingClient;
    machineAgent($client, $state)->tick(1060.0);

    expect($client->posts[0]['payload']['connectivity'])->toBeNull();
});
