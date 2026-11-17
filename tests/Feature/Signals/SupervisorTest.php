<?php

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use KobaltDigital\PingPong\Signals\Pm2;
use KobaltDigital\PingPong\Signals\Supervisor;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

const SUPERVISOR_SITE = '/home/forge/example.com';

/**
 * @param  array<string, string>  $files
 * @param  array<string, bool>  $sockets
 * @param  array<string, string>  $realpaths
 */
function supervisorSignal(
    ?string $binary = '/usr/bin/supervisorctl',
    array $files = [],
    array $sockets = [],
    array $realpaths = [],
): Supervisor {
    return new Supervisor(new FakeHost(
        executables: $binary === null ? [] : ['supervisorctl' => $binary],
        sockets: $sockets,
        files: $files,
        realpaths: $realpaths,
    ));
}

/**
 * A Forge daemon: a program whose processes are named after it, run from
 * the given directory.
 */
function forgeDaemon(string $name, string $directory, string $command = 'php8.3 artisan queue:work redis --sleep=3 --tries=3'): string
{
    return <<<INI
    [program:{$name}]
    command={$command}
    process_name=%(program_name)s_%(process_num)02d
    autostart=true
    autorestart=true
    user=forge
    numprocs=1
    directory={$directory}
    stdout_logfile=/home/forge/.forge/{$name}.log
    INI;
}

function supervisorStatus(string ...$lines): void
{
    Process::fake(['*' => Process::result(implode("\n", $lines)."\n")]);
}

beforeEach(fn () => app()->setBasePath(SUPERVISOR_SITE));

it('is left out when supervisorctl is not on the PATH, without running anything', function () {
    Process::fake();

    expect(supervisorSignal(binary: null)->collect())->toBeNull();

    Process::assertNothingRan();
});

it('reports a socket it may not write to without running anything', function () {
    Process::fake();

    expect(supervisorSignal(sockets: [Supervisor::SOCKET => false])->collect())->toBe([
        'processes' => null,
        'others' => null,
        'error' => 'Permission denied on /var/run/supervisor.sock',
    ]);

    Process::assertNothingRan();
});

it('runs supervisorctl status when it may write to the socket', function () {
    supervisorStatus();

    $signal = supervisorSignal(sockets: [Supervisor::SOCKET => true]);

    expect($signal->name())->toBe('supervisor')
        ->and($signal->collect())->toBe(['processes' => [], 'others' => 0, 'error' => null]);

    Process::assertRan(fn ($process) => $process->command === ['/usr/bin/supervisorctl', 'status'] && $process->timeout === Supervisor::TIMEOUT_SECONDS);
});

it('reports every status, with uptime only while running', function (string $status, string $description, ?int $uptime) {
    supervisorStatus("worker-1                         {$status}   {$description}");

    expect(supervisorSignal()->collect()['processes'][0])->toMatchArray([
        'name' => 'worker-1',
        'status' => $status,
        'uptime_seconds' => $uptime,
    ]);
})->with([
    'running for days' => ['RUNNING', 'pid 1234, uptime 3 days, 2:03:04', 3 * 86400 + 7384],
    'running for a day' => ['RUNNING', 'pid 1234, uptime 1 day, 2:03:04', 86400 + 7384],
    'running for hours' => ['RUNNING', 'pid 1234, uptime 2:03:04', 7384],
    'stopped' => ['STOPPED', 'Oct 20 12:00 PM', null],
    'starting' => ['STARTING', '', null],
    'backoff' => ['BACKOFF', 'Exited too quickly (process log may have details)', null],
    'stopping' => ['STOPPING', '', null],
    'exited' => ['EXITED', 'Oct 20 12:00 PM', null],
    'fatal' => ['FATAL', 'Exited too quickly (process log may have details)', null],
    'unknown' => ['UNKNOWN', '', null],
]);

it('reports output without a process line as an error', function () {
    Process::fake(['*' => Process::result("\nunix:///var/run/supervisor.sock no such file\n", exitCode: 4)]);

    expect(supervisorSignal()->collect())->toBe([
        'processes' => null,
        'others' => null,
        'error' => 'unix:///var/run/supervisor.sock no such file',
    ]);
});

it('reports an error printed only to stderr, cut to what PingPong stores', function () {
    Process::fake(['*' => Process::result(errorOutput: "error: <class 'PermissionError'>, ".str_repeat('x', 300)."\n", exitCode: 2)]);

    $error = supervisorSignal()->collect()['error'];

    expect($error)->toStartWith("error: <class 'PermissionError'>")
        ->and(mb_strlen($error))->toBe(255);
});

it('reports empty output as no processes', function () {
    Process::fake(['*' => Process::result('', exitCode: 0)]);

    expect(supervisorSignal()->collect())->toBe(['processes' => [], 'others' => 0, 'error' => null]);
});

it('reports an error when supervisorctl fails without output', function () {
    Process::fake(['*' => Process::result('', exitCode: 2)]);

    expect(supervisorSignal()->collect())->toBe([
        'processes' => null,
        'others' => null,
        'error' => 'supervisorctl status exited without reporting processes',
    ]);
});

it('reads the list whatever the exit code', function () {
    Process::fake(['*' => Process::result("worker-1   FATAL   Exited too quickly\n", exitCode: 3)]);

    expect(supervisorSignal()->collect()['processes'][0]['status'])->toBe('FATAL');
});

it('reports a status that does not answer in time', function () {
    Process::fake(['*' => fn () => throw new ProcessTimedOutException(
        new SymfonyProcessTimedOutException(new SymfonyProcess(['supervisorctl', 'status']), SymfonyProcessTimedOutException::TYPE_GENERAL),
        new FakeProcessResult,
    )]);

    expect(supervisorSignal()->collect())->toBe([
        'processes' => null,
        'others' => null,
        'error' => 'supervisorctl status did not answer within 3 seconds',
    ]);
});

it('keeps at most a hundred processes, in supervisor order', function () {
    supervisorStatus(...array_map(fn (int $index) => sprintf('worker-%03d   RUNNING   pid %d, uptime 0:01:00', 120 - $index, $index), range(0, 120)));

    $processes = supervisorSignal()->collect()['processes'];

    expect($processes)->toHaveCount(Supervisor::MAX_PROCESSES)
        ->and($processes[0]['name'])->toBe('worker-120')
        ->and(end($processes)['name'])->toBe('worker-021');
});

it('cuts a name to what PingPong stores', function () {
    supervisorStatus(str_repeat('a', 300).'   RUNNING   pid 1, uptime 0:00:01');

    expect(supervisorSignal()->collect()['processes'][0]['name'])->toBe(str_repeat('a', Supervisor::MAX_LENGTH));
});

it('sends what an own daemon runs and counts the daemons of other sites', function () {
    supervisorStatus(
        'daemon-1:daemon-1_00             RUNNING   pid 101, uptime 1 day, 2:03:04',
        'daemon-2:daemon-2_00             RUNNING   pid 102, uptime 0:10:00',
        'daemon-3:daemon-3_00             STOPPED   Oct 20 12:00 PM',
        'ssr                              RUNNING   pid 104, uptime 0:10:00',
    );

    $signal = supervisorSignal(files: [
        Supervisor::MAIN_CONFIG => "[unix_http_server]\nfile=/var/run/supervisor.sock\nchmod=0700\n\n[include]\nfiles = /etc/supervisor/conf.d/*.conf\n",
        '/etc/supervisor/conf.d/daemon-1.conf' => forgeDaemon('daemon-1', SUPERVISOR_SITE),
        '/etc/supervisor/conf.d/daemon-2.conf' => forgeDaemon('daemon-2', '/home/forge/other.com'),
        '/etc/supervisor/conf.d/daemon-3.conf' => forgeDaemon('daemon-3', '/home/forge/third.com'),
        '/etc/supervisor/conf.d/ssr.conf' => "[program:ssr]\ncommand=/usr/bin/node ".SUPERVISOR_SITE."/bootstrap/ssr/ssr.mjs --port=13714\n",
    ])->collect();

    expect($signal)->toBe([
        'processes' => [
            ['name' => 'daemon-1:daemon-1_00', 'status' => 'RUNNING', 'uptime_seconds' => 93784, 'own' => true, 'command' => 'queue:work redis --sleep=3 --tries=3', 'display_name' => 'Queue worker', 'display_id' => '#1/00'],
            ['name' => 'ssr', 'status' => 'RUNNING', 'uptime_seconds' => 600, 'own' => true, 'command' => 'node', 'display_name' => null, 'display_id' => null],
        ],
        'others' => 2,
        'error' => null,
    ]);
});

it('knows a daemon runs from the app', function (string $basePath, string $directory, array $realpaths = []) {
    app()->setBasePath($basePath);

    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 101, uptime 0:10:00');

    $process = supervisorSignal(
        files: ['/etc/supervisor/conf.d/daemon-1.conf' => forgeDaemon('daemon-1', $directory)],
        realpaths: $realpaths,
    )->collect()['processes'][0];

    expect($process['own'])->toBeTrue();
})->with([
    'its directory' => [SUPERVISOR_SITE, SUPERVISOR_SITE],
    'with a trailing slash' => [SUPERVISOR_SITE, SUPERVISOR_SITE.'/'],
    'the site of a release' => [SUPERVISOR_SITE.'/releases/20261020120000', SUPERVISOR_SITE],
    'the current release' => [SUPERVISOR_SITE.'/releases/20261020120000', SUPERVISOR_SITE.'/current'],
    'a link to it' => [SUPERVISOR_SITE, '/home/forge/app', ['/home/forge/app' => SUPERVISOR_SITE]],
]);

it('leaves ownership unknown for a relative Artisan command outside the site root', function () {
    app()->setBasePath(SUPERVISOR_SITE);
    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 42, uptime 0:01:00');

    $process = supervisorSignal(files: [
        '/etc/supervisor/conf.d/daemon-1.conf' => forgeDaemon('daemon-1', '/home/forge'),
    ])->collect()['processes'][0];

    expect($process['own'])->toBeNull();
});

it('knows a daemon runs from the app by the path in its command', function (string $command, bool $own) {
    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 101, uptime 0:10:00');

    $process = supervisorSignal(files: [
        '/etc/supervisor/conf.d/daemon-1.conf' => "[program:daemon-1]\ncommand={$command}\ndirectory=/home/forge\n",
    ])->collect();

    expect($own ? $process['processes'][0]['own'] : $process['others'])->toBe($own ? true : 1);
})->with([
    'its artisan' => ['php '.SUPERVISOR_SITE.'/artisan horizon', true],
    'at the end' => ['/usr/local/bin/serve '.SUPERVISOR_SITE, true],
    'a sibling path' => ['php '.SUPERVISOR_SITE.'-old/artisan horizon', false],
    'another site' => ['php /home/forge/other.com/artisan horizon', false],
]);

it('cannot tell who owns a process without a program', function () {
    supervisorStatus('stray   RUNNING   pid 101, uptime 0:10:00');

    $signal = supervisorSignal(files: ['/etc/supervisor/conf.d/daemon-1.conf' => forgeDaemon('daemon-1', SUPERVISOR_SITE)])->collect();

    expect($signal['processes'][0])->toMatchArray(['own' => null, 'command' => null])
        ->and($signal['others'])->toBe(0);
});

it('cannot tell who owns any process when no config can be read', function () {
    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 101, uptime 0:10:00');

    expect(supervisorSignal()->collect()['processes'][0])->toMatchArray(['own' => null, 'command' => null]);
});

it('finds the program of a process in a group', function (string $name, ?bool $own) {
    supervisorStatus("{$name}   RUNNING   pid 101, uptime 0:10:00");

    $config = <<<'INI'
    [group:solo]
    programs=reverb

    [group:workers]
    programs=high,
      low

    [program:reverb]
    command=php artisan reverb:start
    directory=/home/forge/example.com

    [program:high]
    command=php artisan queue:work --queue=high
    directory=/home/forge/example.com

    [program:low]
    command=php artisan queue:work --queue=low
    directory=/home/forge/other.com
    INI;

    $signal = supervisorSignal(files: ['/etc/supervisor/conf.d/app.conf' => $config])->collect();

    expect($own === false ? $signal['others'] : $signal['processes'][0]['own'])->toBe($own === false ? 1 : $own);
})->with([
    'the only program of its group' => ['solo:reverb', true],
    'named after its program' => ['workers:high', true],
    'an instance of its program' => ['workers:high_01', true],
    'another site in the group' => ['workers:low_00', false],
    'no program of the group' => ['workers:other', null],
]);

it('reads supervisor config with continuation lines, colons and comments', function () {
    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 101, uptime 0:10:00');

    $config = <<<'INI'
    ; Managed by Forge
    # Also a comment
    [program:daemon-1]
    command: php8.3
        artisan queue:work redis ; the worker
        --sleep=3
    directory : /home/forge/example.com ; the site
    INI;

    $process = supervisorSignal(files: ['/etc/supervisor/conf.d/daemon-1.conf' => $config])->collect()['processes'][0];

    expect($process)->toMatchArray(['own' => true, 'command' => 'queue:work redis --sleep=3']);
});

it('skips a config file it cannot read', function () {
    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 101, uptime 0:10:00');

    $host = new class(executables: ['supervisorctl' => '/usr/bin/supervisorctl'], files: ['/etc/supervisor/conf.d/daemon-1.conf' => forgeDaemon('daemon-1', SUPERVISOR_SITE), '/etc/supervisor/conf.d/broken.conf' => '']) extends FakeHost
    {
        public function contents(string $path): ?string
        {
            return str_ends_with($path, 'broken.conf') ? null : parent::contents($path);
        }
    };

    expect((new Supervisor($host))->collect()['processes'][0]['own'])->toBeTrue();
});

it('sends only what an own process runs, never its command line', function (string $command, ?string $sent) {
    supervisorStatus('daemon-1:daemon-1_00   RUNNING   pid 101, uptime 0:10:00');

    $process = supervisorSignal(files: ['/etc/supervisor/conf.d/daemon-1.conf' => forgeDaemon('daemon-1', SUPERVISOR_SITE, $command)])->collect()['processes'][0];

    expect($process['command'])->toBe($sent);
})->with([
    'an artisan command' => ['php8.3 artisan queue:work redis --sleep=3 --tries=3', 'queue:work redis --sleep=3 --tries=3'],
    'artisan by its path' => ['/usr/bin/php '.SUPERVISOR_SITE.'/artisan horizon', 'horizon'],
    'another executable' => ['/usr/bin/node server.js --token=secret', 'node'],
    'a long artisan command' => ['php artisan '.str_repeat('x', 300), str_repeat('x', Supervisor::MAX_LENGTH)],
]);

it('is configured by default after pm2', function () {
    $signals = config('pingpong-agent.signals');

    expect(array_search(Supervisor::class, $signals, true))
        ->toBe(array_search(Pm2::class, $signals, true) + 1);
});
