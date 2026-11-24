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
        ->and($payload['signals']['disks'][0]['mount'])->toBe('/')
        ->and($payload['sent_at'])->toBe('1970-01-01T00:16:40Z')
        ->and($payload['processes']['process_count'])->toBe(1)
        ->and($payload['processes']['users'][0]['names'])->toBe([
            ['name' => 'php', 'count' => 1, 'cpu_seconds' => null, 'rss_bytes' => 40_960],
        ]);
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

it('reports per-process CPU seconds and RSS for normalized process names across ticks', function () {
    $state = stateDirectory();
    $client = new RecordingClient;

    $firstProc = fakeProc([
        1 => ['stat' => statLine(1, 'systemd', 10, 0, 1, 100)],
        10 => ['stat' => statLine(10, 'php8.4', 100, 15, 100, 1000), 'cmdline' => "/usr/bin/php8.4\0/app/artisan\0horizon\0"],
        11 => ['stat' => statLine(11, 'php-fpm8.4', 50, 10, 100, 2000), 'cmdline' => "php-fpm: pool mytiemlin\0"],
        12 => ['stat' => statLine(12, 'php-fpm8.4', 30, 5, 100, 1500), 'cmdline' => "php-fpm: pool mytiemlin\0"],
    ]);

    $secondProc = fakeProc([
        1 => ['stat' => statLine(1, 'systemd', 10, 0, 1, 100)],
        10 => ['stat' => statLine(10, 'php8.4', 200, 30, 100, 1200), 'cmdline' => "/usr/bin/php8.4\0/app/artisan\0horizon\0"],
        11 => ['stat' => statLine(11, 'php-fpm8.4', 100, 20, 100, 2500), 'cmdline' => "php-fpm: pool mytiemlin\0"],
        12 => ['stat' => statLine(12, 'php-fpm8.4', 60, 15, 100, 1500), 'cmdline' => "php-fpm: pool mytiemlin\0"],
    ]);

    machineAgent($client, $state, $firstProc)->tick(1000.0);
    machineAgent($client, $state, $secondProc)->tick(1060.0);

    $names = array_column($client->posts[1]['payload']['processes']['users'][0]['names'], null, 'name');

    expect($names['php artisan horizon'])->toBe([
        'name' => 'php artisan horizon',
        'count' => 1,
        'cpu_seconds' => 1.15,
        'rss_bytes' => 1200 * 4096,
    ])->and($names['php-fpm: mytiemlin'])->toBe([
        'name' => 'php-fpm: mytiemlin',
        'count' => 2,
        'cpu_seconds' => 1.0,
        'rss_bytes' => 4000 * 4096,
    ]);
});

it('persists watch_users and watch_processes from tick responses and honors them on subsequent ticks', function () {
    $state = stateDirectory();
    $procWithMachineScope = fakeProc([
        1 => ['stat' => statLine(1, 'systemd', 10, 0, 1, 100)],
        10 => ['stat' => statLine(10, 'php8.4', 100, 0, 100, 500), 'cmdline' => "/usr/bin/php8.4\0/app/artisan\0horizon\0"],
    ]);

    /* Tick 1: server returns watch_users and watch_processes (including invalid/duplicate items to sanitize). */
    $watchClient = new RecordingClient(200, [
        'status' => 'recorded',
        'watch_users' => ['mytiemlin', 'mytiemlin', '', 123, 'root'],
        'watch_processes' => [
            ['user' => 'mytiemlin', 'process' => 'php artisan horizon'],
            ['user' => 'root', 'process' => 'mysqld'],
            ['user' => '', 'process' => 'ignored'],
            'malformed',
        ],
    ]);

    machineAgent($watchClient, $state, $procWithMachineScope)->tick(1000.0);

    /* Non-2xx response must not overwrite persisted watch rules. */
    machineAgent(new RecordingClient(503, ['watch_users' => [], 'watch_processes' => []]), $state, $procWithMachineScope)->tick(1030.0);

    /* Tick 2: uses persisted watch rules from Tick 1 (and keeps them when the response still lists them). */
    $keepClient = new RecordingClient(200, [
        'status' => 'recorded',
        'watch_users' => ['mytiemlin', 'root'],
        'watch_processes' => [
            ['user' => 'mytiemlin', 'process' => 'php artisan horizon'],
            ['user' => 'root', 'process' => 'mysqld'],
        ],
    ]);
    machineAgent($keepClient, $state, $procWithMachineScope)->tick(1060.0);

    $users = array_column($keepClient->posts[0]['payload']['processes']['users'], null, 'user');

    expect($keepClient->posts[0]['payload']['processes']['scope'])->toBe('machine')
        ->and($users['mytiemlin'])->toBe([
            'user' => 'mytiemlin',
            'processes' => 0,
            'cpu_seconds' => 0.0,
            'rss_bytes' => 0,
            'swap_bytes' => 0,
            'names' => [
                ['name' => 'php artisan horizon', 'count' => 0, 'cpu_seconds' => 0.0, 'rss_bytes' => 0],
            ],
        ])
        ->and($users['root'])->toBe([
            'user' => 'root',
            'processes' => 0,
            'cpu_seconds' => 0.0,
            'rss_bytes' => 0,
            'swap_bytes' => 0,
            'names' => [
                ['name' => 'mysqld', 'count' => 0, 'cpu_seconds' => 0.0, 'rss_bytes' => 0],
            ],
        ]);

    /* Tick 3: server clears watch rules with empty arrays. */
    $clearClient = new RecordingClient(200, [
        'status' => 'recorded',
        'watch_users' => [],
        'watch_processes' => [],
    ]);
    machineAgent($clearClient, $state, $procWithMachineScope)->tick(1120.0);

    /* Tick 4: cleared watch rules are no longer emitted. */
    $afterClearClient = new RecordingClient;
    machineAgent($afterClearClient, $state, $procWithMachineScope)->tick(1180.0);

    $afterUsers = array_column($afterClearClient->posts[0]['payload']['processes']['users'], null, 'user');

    expect($afterUsers)->not->toHaveKey('mytiemlin')
        ->and($afterUsers)->not->toHaveKey('root');
});

it('clears previously persisted watch rules when a later 2xx tick response omits those keys', function () {
    $state = stateDirectory();
    $procWithMachineScope = fakeProc([
        1 => ['stat' => statLine(1, 'systemd', 10, 0, 1, 100)],
        10 => ['stat' => statLine(10, 'php8.4', 100, 0, 100, 500), 'cmdline' => "/usr/bin/php8.4\0/app/artisan\0horizon\0"],
    ]);

    machineAgent(new RecordingClient(200, [
        'status' => 'recorded',
        'watch_users' => ['mytiemlin'],
        'watch_processes' => [['user' => 'root', 'process' => 'mysqld']],
    ]), $state, $procWithMachineScope)->tick(1000.0);

    /* Next 2xx tick response omits watch_users and watch_processes (e.g. last trigger removed). */
    $secondClient = new RecordingClient(200, ['status' => 'recorded']);
    machineAgent($secondClient, $state, $procWithMachineScope)->tick(1060.0);

    $secondUsers = array_column($secondClient->posts[0]['payload']['processes']['users'], null, 'user');

    /* Tick 2 still reported the rules stored after Tick 1, and its response cleared them in state. */
    expect($secondUsers)->toHaveKeys(['mytiemlin', 'root'])
        ->and((new State($state))->get('watch_users'))->toBe([])
        ->and((new State($state))->get('watch_processes'))->toBe([]);

    /* Tick 3 no longer emits the removed watches. */
    $thirdClient = new RecordingClient(200, ['status' => 'recorded']);
    machineAgent($thirdClient, $state, $procWithMachineScope)->tick(1120.0);

    $thirdUsers = array_column($thirdClient->posts[0]['payload']['processes']['users'], null, 'user');

    expect($thirdUsers)->not->toHaveKey('mytiemlin')
        ->and($thirdUsers)->not->toHaveKey('root');
});

it('only emits watched rules for the current unix user when scope is user', function () {
    $state = stateDirectory();
    /* No PID 1 stat -> seesEveryone() is false -> scope === 'user'. */
    $procUserScope = fakeProc([
        10 => ['stat' => statLine(10, 'php', 100, 0, 100, 10)],
    ]);

    $currentUser = (new Users)->current();

    $watchClient = new RecordingClient(200, [
        'status' => 'recorded',
        'watch_users' => [$currentUser, 'other-tenant'],
        'watch_processes' => [
            ['user' => $currentUser, 'process' => 'php artisan queue:work'],
            ['user' => 'other-tenant', 'process' => 'mysqld'],
        ],
    ]);

    machineAgent($watchClient, $state, $procUserScope)->tick(1000.0);

    $nextClient = new RecordingClient;
    machineAgent($nextClient, $state, $procUserScope)->tick(1060.0);

    $report = $nextClient->posts[0]['payload']['processes'];
    $users = array_column($report['users'], null, 'user');
    $currentNames = array_column($users[$currentUser]['names'], null, 'name');

    expect($report['scope'])->toBe('user')
        ->and($users)->toHaveKey($currentUser)
        ->and($users)->not->toHaveKey('other-tenant')
        ->and($currentNames['php artisan queue:work'])->toBe([
            'name' => 'php artisan queue:work',
            'count' => 0,
            'cpu_seconds' => 0.0,
            'rss_bytes' => 0,
        ]);
});

it('remains backward compatible when the server omits watch_users and watch_processes', function () {
    $state = stateDirectory();
    $client = new RecordingClient(200, ['status' => 'recorded']);

    expect(machineAgent($client, $state)->tick(1000.0))->toBeTrue()
        ->and(machineAgent($client, $state)->tick(1060.0))->toBeTrue()
        ->and((new State($state))->get('watch_users'))->toBe([])
        ->and((new State($state))->get('watch_processes'))->toBe([]);
});
