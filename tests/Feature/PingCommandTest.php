<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const TICK_URL = 'https://pingpong.kobaltdigital.nl/api/agent/tick';

function scheduledPings(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command, 'pingpong:ping'))
        ->values()
        ->all();
}

it('runs the ping every minute in the foreground without overlapping', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $pings = scheduledPings();

    expect($pings)->toHaveCount(1)
        ->and($pings[0]->expression)->toBe('* * * * *')
        ->and($pings[0]->withoutOverlapping)->toBeTrue()
        ->and($pings[0]->runInBackground)->toBeFalse();
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

    createFailedJobsTable();
    useDatabaseQueue();

    app(Schedule::class)->command('backup:run --only-db')
        ->description('Nightly database backup')
        ->dailyAt('03:00')
        ->pingpong(maxRuntime: 240, grace: 10);

    Http::fake([TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer pp_agent_test')
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
