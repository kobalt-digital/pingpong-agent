<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const MANIFEST_TICK_URL = 'https://pingpong.kobaltdigital.nl/api/agent/tick';

beforeEach(function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
});

/**
 * @return array<int, array<string, mixed>>
 */
function sentTicks(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === MANIFEST_TICK_URL)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

it('sends the task list with the first tick', function () {
    app(Schedule::class)->command('backup:run --only-db')
        ->description('Nightly database backup')
        ->dailyAt('03:00')
        ->timezone('Europe/Amsterdam')
        ->pingpong(maxRuntime: 240, grace: 10);

    Http::fake([MANIFEST_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');

    expect(sentTicks()[0]['schedule_hash'])->toBeString()->toHaveLength(64)
        ->and(sentTicks()[0]['tasks'])->toBe([
            [
                'slug' => 'backup-run-only-db',
                'command' => 'backup:run --only-db',
                'description' => 'Nightly database backup',
                'cron' => '0 3 * * *',
                'timezone' => 'Europe/Amsterdam',
                'overrides' => [
                    'max_runtime' => 240,
                    'grace' => 10,
                ],
            ],
        ]);
});

it('sends no task list while the schedule is unchanged', function () {
    app(Schedule::class)->command('backup:run')->daily();

    Http::fake([MANIFEST_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    [$first, $second] = sentTicks();

    expect($second)->not->toHaveKey('tasks')
        ->and($second['schedule_hash'])->toBe($first['schedule_hash']);
});

it('sends the task list again once the schedule changes', function () {
    app(Schedule::class)->command('backup:run')->daily();

    Http::fake([MANIFEST_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');

    app(Schedule::class)->command('reports:send')->weekly();

    $this->artisan('pingpong:ping');

    [$first, $second] = sentTicks();

    expect($second['schedule_hash'])->not->toBe($first['schedule_hash'])
        ->and(collect($second['tasks'])->pluck('slug')->all())->toBe(['backup-run', 'reports-send']);
});

it('sends the task list again when the tick that carried it was not delivered', function () {
    app(Schedule::class)->command('backup:run')->daily();

    Http::fake([MANIFEST_TICK_URL => Http::sequence()->push(status: 500)->push()]);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    expect(sentTicks()[1])->toHaveKey('tasks');
});

it('lists only the tasks it checks in, once per slug and sorted by slug', function () {
    $schedule = app(Schedule::class);

    $schedule->command('reports:send')->weekly();
    $schedule->command('backup:run')->daily();
    $schedule->command('backup:run')->hourly();
    $schedule->call(fn () => null)->name('prune')->daily();
    $schedule->call(fn () => null)->daily();
    $schedule->command('horizon:snapshot')->everyThirtySeconds();

    Http::fake([MANIFEST_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');

    expect(collect(sentTicks()[0]['tasks'])->pluck('slug')->all())->toBe(['backup-run', 'prune', 'reports-send']);
});

it('sends an empty task list for an app without tasks of its own', function () {
    Http::fake([MANIFEST_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');

    Http::assertSent(fn (Request $request) => $request['tasks'] === []);
});

it('sends the task list again on the next tick when PingPong asks for it', function () {
    app(Schedule::class)->command('backup:run')->daily();

    Http::fake([MANIFEST_TICK_URL => Http::sequence()
        ->push(['status' => 'recorded'])
        ->push(['status' => 'recorded', 'send_tasks' => true])
        ->push(['status' => 'recorded'])
        ->push(['status' => 'recorded']),
    ]);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    [, $asked, $resent, $after] = sentTicks();

    expect($asked)->not->toHaveKey('tasks')
        ->and(collect($resent['tasks'])->pluck('slug')->all())->toBe(['backup-run'])
        ->and($after)->not->toHaveKey('tasks');
});

it('keeps the task list cached when PingPong does not ask for it', function (mixed $body) {
    app(Schedule::class)->command('backup:run')->daily();

    Http::fake([MANIFEST_TICK_URL => Http::response($body)]);

    $this->artisan('pingpong:ping')->assertSuccessful();
    $this->artisan('pingpong:ping')->assertSuccessful();

    expect(sentTicks()[1])->not->toHaveKey('tasks');
})->with([
    'no flag' => [['status' => 'recorded']],
    'flag false' => [['status' => 'recorded', 'send_tasks' => false]],
    'flag not a boolean' => [['status' => 'recorded', 'send_tasks' => 'true']],
    'unknown keys' => [['status' => 'recorded', 'send_everything' => true]],
    'not json' => ['<html>ok</html>'],
    'json but not an object' => ['"recorded"'],
    'empty body' => [''],
]);
