<?php

use KobaltDigital\PingPong\Host;
use KobaltDigital\PingPong\Machine\Agent;
use KobaltDigital\PingPong\Machine\Client;
use KobaltDigital\PingPong\Machine\Config;
use KobaltDigital\PingPong\Machine\ProcessReport;
use KobaltDigital\PingPong\Machine\ProcessTable;
use KobaltDigital\PingPong\Machine\Runner;
use KobaltDigital\PingPong\Machine\SiteSizes;
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

function machineAgent(RecordingClient $client, string $stateDirectory, ?string $proc = null, ?string $du = null): Agent
{
    $runner = new Runner;
    $users = new Users;
    $proc ??= fakeProc([10 => ['stat' => statLine(10, 'php', 100, 0, 100, 10)]]);

    return new Agent(
        new Config('https://pingpong.test', 'pp_machine_test', $stateDirectory, $du),
        $client,
        new State($stateDirectory),
        new Vitals(new Host, $runner),
        new ProcessTable($proc),
        new ProcessReport($users),
        new SiteSizes($runner, $users),
        $runner,
        $users,
    );
}

function stateDirectory(): string
{
    return sys_get_temp_dir().'/pingpong-machine-'.bin2hex(random_bytes(4));
}

it('sends who it is, the machine and its processes in one tick', function () {
    $client = new RecordingClient(body: ['status' => 'recorded', 'measure_sites' => true]);

    $tick = machineAgent($client, stateDirectory())->tick(1000.0);

    $payload = $client->posts[0]['payload'];

    expect($tick)->toMatchArray(['sent' => true, 'measure_sites' => true])
        ->and($client->posts[0]['path'])->toBe('api/machine/tick')
        ->and($payload)->toHaveKeys(['schema', 'version', 'server', 'machine_id', 'os_name', 'kernel', 'cores', 'unix_user', 'mode', 'sent_at', 'signals', 'host', 'processes'])
        ->and($payload['sent_at'])->toBe('1970-01-01T00:16:40Z')
        ->and($payload['processes']['process_count'])->toBe(1)
        ->and($payload['processes']['users'][0]['names'])->toBe([['name' => 'php', 'count' => 1]]);
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

it('does not ask for the sites when PingPong refused the tick', function () {
    $tick = machineAgent(new RecordingClient(401, ['message' => 'Unknown key'] + ['measure_sites' => true]), stateDirectory())->tick();

    expect($tick)->toMatchArray(['sent' => false, 'measure_sites' => false]);
});

it('measures the sites once an hour, or after a quarter when PingPong asks', function () {
    $state = stateDirectory();
    $agent = machineAgent(new RecordingClient, $state, du: fakeDu(''));

    expect($agent->sitesDue(false, now: 1000.0))->toBeTrue();

    $agent->sites(1000.0);

    expect($agent->sitesDue(false, now: 1000.0 + 3599))->toBeFalse()
        ->and($agent->sitesDue(true, now: 1000.0 + 899))->toBeFalse()
        ->and($agent->sitesDue(true, now: 1000.0 + 900))->toBeTrue()
        ->and($agent->sitesDue(false, now: 1000.0 + 3600))->toBeTrue()
        ->and(machineAgent(new RecordingClient, $state)->sitesDue(false, now: 1000.0 + 60))->toBeFalse();
});

it('waits with the sites while the machine is busy, for six hours at most', function () {
    $state = stateDirectory();
    $agent = machineAgent(new RecordingClient, $state, du: fakeDu(''));
    $agent->sites(1000.0);

    expect($agent->sitesDue(true, busy: true, now: 1000.0 + 3600))->toBeFalse()
        ->and($agent->sitesDue(false, busy: true, now: 1000.0 + 6 * 3600))->toBeTrue();
});

it('calls a machine busy that waits on its disk or has more load than cores', function (array $signals, bool $busy) {
    $cores = (new Host)->cores() ?? 1;

    expect(machineAgent(new RecordingClient, stateDirectory())->busy([
        'load' => isset($signals['load']) ? ['1m' => 0.0, '5m' => $signals['load'] * $cores, '15m' => 0.0] : null,
        'pressure' => isset($signals['io']) ? ['io_some' => $signals['io']] : null,
    ]))->toBe($busy);
})->with([
    'quiet' => [['load' => 0.3, 'io' => 1.0], false],
    'waiting on its disk' => [['load' => 0.3, 'io' => 25.0], true],
    'more load than cores' => [['load' => 1.5], true],
    'nothing known' => [[], false],
]);

it('sends the sizes of the sites in a request of their own', function () {
    $client = new RecordingClient;

    $sent = machineAgent($client, stateDirectory(), du: fakeDu(''))->sites(1000.0);

    expect($sent)->toBeTrue()
        ->and($client->posts[0]['path'])->toBe('api/machine/sites')
        ->and($client->posts[0]['payload'])->toHaveKeys(['server', 'unix_user', 'sites', 'complete', 'unmeasured', 'databases', 'duration_ms']);
});

it('never measures the sites twice at once', function () {
    $state = stateDirectory();
    mkdir($state);
    $lock = fopen("{$state}/sites.lock", 'c');
    flock($lock, LOCK_EX);

    $client = new RecordingClient;

    expect(machineAgent($client, $state, du: fakeDu(''))->sites())->toBeNull()
        ->and($client->posts)->toBe([]);

    flock($lock, LOCK_UN);
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
