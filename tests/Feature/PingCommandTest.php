<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use KobaltDigital\PingPong\Host;
use KobaltDigital\PingPong\InstalledPackages;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;
use KobaltDigital\PingPong\Tests\Fakes\FakeInstalledPackages;

const TICK_URL = 'https://pingpong.kobaltdigital.nl/api/agent/tick';

function scheduledPings(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command, 'pingpong:ping'))
        ->values()
        ->all();
}

it('runs the ping every minute in the foreground without overlapping, even in maintenance mode', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $pings = scheduledPings();

    expect($pings)->toHaveCount(1)
        ->and($pings[0]->expression)->toBe('* * * * *')
        ->and($pings[0]->withoutOverlapping)->toBeTrue()
        ->and($pings[0]->runInBackground)->toBeFalse()
        ->and($pings[0]->evenInMaintenanceMode)->toBeTrue();
});

it('runs the ping at the interval the environment sets', function (int|string $minutes, string $expression) {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.tick_minutes', $minutes);

    expect(scheduledPings()[0]->expression)->toBe($expression);
})->with([
    'every five minutes' => [5, '*/5 * * * *'],
    'from the env as a string' => ['15', '*/15 * * * *'],
    'every minute' => [1, '* * * * *'],
]);

it('runs the ping every minute when the interval does not divide an hour', function (int|string $minutes) {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.tick_minutes', $minutes);

    Log::spy();

    expect(scheduledPings()[0]->expression)->toBe('* * * * *');

    Log::shouldHaveReceived('warning')->once();
})->with([7, 0, -5, 'five']);

it('runs the ping every minute with a config published before the interval existed', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent', Arr::except(config('pingpong-agent'), 'tick_minutes'));

    Log::spy();

    expect(scheduledPings()[0]->expression)->toBe('* * * * *');

    Log::shouldNotHaveReceived('warning');
});

it('tells PingPong the interval in the tick', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.tick_minutes', 5);

    Http::fake([TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['tick_minutes'] === 5);
});

it('schedules nothing without a key', function () {
    config()->set('pingpong-agent.key', null);

    expect(scheduledPings())->toBeEmpty();
});

it('schedules nothing when disabled', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.enabled', false);

    expect(scheduledPings())->toBeEmpty();
});

it('schedules nothing while the app runs its unit tests', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    app()['env'] = 'testing';

    expect(scheduledPings())->toBeEmpty();
});

it('sends a tick shaped like the schema 1 fixture', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $this->app->instance(Host::class, new FakeHost(executables: ['pm2' => '/usr/bin/pm2'], livePidFiles: ['/srv/pm2/pm2.pid']));
    Process::fake(['*' => Process::result(json_encode([[
        'name' => 'queue-worker',
        'pm_id' => 0,
        'monit' => ['memory' => 52428800],
        'pm2_env' => ['status' => 'online', 'restart_time' => 3, 'pm_uptime' => now()->subDay()->getTimestampMs()],
    ]]))]);

    createFailedJobsTable();
    useDatabaseQueue();
    insertJob('default', now()->getTimestamp() - 40);
    insertJob('mail', now()->getTimestamp() - 5);
    useComposerLock(['laravel/framework' => 'v13.2.0']);

    app(Schedule::class)->command('backup:run --only-db')
        ->description('Nightly database backup')
        ->dailyAt('03:00')
        ->pingpong(maxRuntime: 240, grace: 10);

    $this->app->instance(InstalledPackages::class, new FakeInstalledPackages(['spatie/laravel-flare' => '3.2.1']));
    config()->set('flare.key', 'flare_test_key');
    config()->set('flare.sender.class', 'Spatie\FlareClient\Senders\DaemonSender');
    app(ExceptionHandler::class)->reportable(fn (Throwable $exception): ?\Spatie\FlareClient\Flare => null);

    Http::fake([
        TICK_URL => Http::response(),
        'http://127.0.0.1:8787/health' => Http::response(['status' => 'ok']),
        'https://ingress.flareapp.io/v1/errors' => Http::response([], 201),
    ]);

    withEnv(['PM2_HOME' => '/srv/pm2'], function () {
        $this->artisan('pingpong:ping')->assertSuccessful();
    });

    Http::assertSent(fn (Request $request) => $request->url() === TICK_URL
        && $request->hasHeader('Authorization', 'Bearer pp_agent_test')
        && payloadShape(json_decode($request->body())) === payloadShape(payloadFixture('tick')));
});

it('sends no tick without a key', function () {
    config()->set('pingpong-agent.key', null);

    Http::fake([TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertNothingSent();
});

it('does not fail when PingPong is down', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake([TICK_URL => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);

    $this->artisan('pingpong:ping')->assertSuccessful();
});

it('reports a failing check in the tick instead of failing', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('database.connections.testing.database', '/nonexistent/pingpong.sqlite');

    DB::purge();

    Http::fake([TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request['signals']['database']['reachable'] === false
        && $request['signals']['failed_jobs']['new'] === null);
});
