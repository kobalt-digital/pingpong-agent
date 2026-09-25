<?php

use Illuminate\Console\Application;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const CHECK_IN_URL = 'https://pingpong.kobaltdigital.nl/api/agent/check-in';

beforeEach(function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::response()]);
});

/**
 * @return array<int, array<string, mixed>>
 */
function sentCheckIns(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === CHECK_IN_URL)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

it('checks in when a task starts, with its schedule and a run id', function () {
    $task = app(Schedule::class)->command('backup:run --only-db')->dailyAt('03:00')->pingpong(maxRuntime: 240, grace: 10);

    event(new ScheduledTaskStarting($task));

    Http::assertSent(fn (Request $request) => $request->url() === CHECK_IN_URL
        && payloadShape(json_decode($request->body())) === payloadShape(payloadFixture('check-in')));

    expect(sentCheckIns()[0])->toMatchArray([
        'slug' => 'backup-run-only-db',
        'signal' => 'start',
        'cron' => '0 3 * * *',
        'timezone' => 'UTC',
        'exit_code' => null,
        'message' => null,
    ])->and(sentCheckIns()[0]['run_id'])->toBeString()->not->toBeEmpty();
});

it('checks in success under the run id of the start', function () {
    $task = app(Schedule::class)->command('backup:run')->daily();

    event(new ScheduledTaskStarting($task));

    $task->exitCode = 0;

    event(new ScheduledTaskFinished($task, 1.5));

    [$start, $success] = sentCheckIns();

    expect($success['signal'])->toBe('success')
        ->and($success['run_id'])->toBe($start['run_id']);
});

it('gives every run its own run id', function () {
    $task = app(Schedule::class)->command('backup:run')->daily();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskStarting($task));

    [$first, $second] = sentCheckIns();

    expect($first['run_id'])->not->toBe($second['run_id']);
});

it('checks in a failed command once, with its exit code', function () {
    $task = app(Schedule::class)->command('backup:run')->daily()->pingpong(maxRuntime: 240, grace: 10);

    event(new ScheduledTaskStarting($task));

    $task->exitCode = 2;

    event(new ScheduledTaskFinished($task, 1.5));
    event(new ScheduledTaskFailed($task, new Exception('Scheduled command [backup:run] failed with exit code [2].')));

    [$start, $fail] = sentCheckIns();

    expect(sentCheckIns())->toHaveCount(2)
        ->and($fail)->toMatchArray([
            'signal' => 'fail',
            'run_id' => $start['run_id'],
            'exit_code' => 2,
            'message' => 'Scheduled command [backup:run] failed with exit code [2].',
        ]);

    Http::assertSent(fn (Request $request) => $request->url() === CHECK_IN_URL
        && $request['signal'] === 'fail'
        && payloadShape(json_decode($request->body())) === payloadShape(payloadFixture('check-in-fail')));
});

it('keeps the message of a failure within 255 characters', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('prune')->daily();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException(str_repeat('a', 300))));

    expect(mb_strlen(sentCheckIns()[1]['message']))->toBe(255);
});

it('checks in a skipped task under a run id of its own', function () {
    $task = app(Schedule::class)->command('backup:run')->daily();

    event(new ScheduledTaskSkipped($task));

    expect(sentCheckIns()[0]['signal'])->toBe('skipped')
        ->and(sentCheckIns()[0]['run_id'])->toBeString()->not->toBeEmpty();
});

it('checks in a task that skipped itself while running because it overlapped', function () {
    $task = app(Schedule::class)->command('backup:run')->daily();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.0));

    [$start, $skipped] = sentCheckIns();

    expect($skipped['signal'])->toBe('skipped')
        ->and($skipped['run_id'])->toBe($start['run_id']);
});

it('uses the timezone of the task', function () {
    $task = app(Schedule::class)->command('backup:run')->daily()->timezone('Europe/Amsterdam');

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['timezone'])->toBe('Europe/Amsterdam');
});

it('falls back to the app timezone', function () {
    config()->set('app.timezone', 'Europe/Amsterdam');

    $task = app(Schedule::class)->command('backup:run')->daily();

    $task->timezone = null;

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['timezone'])->toBe('Europe/Amsterdam');
});

it('derives the slug from the command, not from the php binary that runs it', function () {
    $task = app(Schedule::class)->command('backup:run --only-db')->daily();

    $task->command = "'/opt/php/8.5/bin/php' ".Application::artisanBinary().' backup:run --only-db';

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['slug'])->toBe('backup-run-only-db');
});

it('derives the slug of a shell command from the command itself', function () {
    $task = app(Schedule::class)->exec('/usr/bin/certbot renew')->daily();

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['slug'])->toBe('usr-bin-certbot-renew');
});

it('derives the slug of a closure from its name', function () {
    $task = app(Schedule::class)->call(fn () => null)->name('Prune old exports')->daily();

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['slug'])->toBe('prune-old-exports');
});

it('keeps a long slug unique within 100 characters', function () {
    $first = app(Schedule::class)->command('report:send '.str_repeat('a', 120).' --first')->daily();
    $second = app(Schedule::class)->command('report:send '.str_repeat('a', 120).' --second')->daily();

    event(new ScheduledTaskStarting($first));
    event(new ScheduledTaskStarting($second));

    [$firstCheckIn, $secondCheckIn] = sentCheckIns();

    expect(strlen($firstCheckIn['slug']))->toBeLessThanOrEqual(100)
        ->and($firstCheckIn['slug'])->not->toBe($secondCheckIn['slug']);
});

it('sends nothing for a closure without a name, and logs why', function () {
    Log::spy();

    $task = app(Schedule::class)->call(fn () => null)->daily();

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns())->toBeEmpty();

    Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'name'));
});

it('ignores sub minute tasks', function () {
    $task = app(Schedule::class)->command('horizon:snapshot')->everyThirtySeconds();

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns())->toBeEmpty();
});

it('does not check in its own ping', function () {
    $ping = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'pingpong:ping'));

    event(new ScheduledTaskStarting($ping));

    expect(sentCheckIns())->toBeEmpty();
});

it('sends no check in without a key', function () {
    config()->set('pingpong-agent.key', null);

    event(new ScheduledTaskStarting(app(Schedule::class)->command('backup:run')->daily()));

    Http::assertNothingSent();
});

it('does not break the scheduler when PingPong is down', function () {
    Http::fake([CHECK_IN_URL => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);

    $task = app(Schedule::class)->command('backup:run')->daily();

    expect(fn () => event(new ScheduledTaskStarting($task)))->not->toThrow(Exception::class);
});

it('checks in the tasks schedule:run runs', function () {
    app(Schedule::class)->call(fn () => null)->name('prune')->everyMinute();

    $this->artisan('schedule:run')->assertSuccessful();

    expect(collect(sentCheckIns())->where('slug', 'prune')->pluck('signal')->all())->toBe(['start', 'success']);
});

it('checks in a closure that throws as failed', function () {
    app(Schedule::class)->call(fn () => throw new RuntimeException('Export disk is full'))->name('export')->everyMinute();

    $this->artisan('schedule:run');

    $checkIns = collect(sentCheckIns())->where('slug', 'export')->values();

    expect($checkIns->pluck('signal')->all())->toBe(['start', 'fail'])
        ->and($checkIns[1]['exit_code'])->toBe(1)
        ->and($checkIns[1]['message'])->toBe('Export disk is full');
});

it('sends the overrides a task sets in code', function () {
    $task = app(Schedule::class)->command('backup:run')->daily()->pingpong(maxRuntime: 240, grace: 10);

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['overrides'])->toBe([
        'max_runtime' => 240,
        'grace' => 10,
    ]);
});

it('sends empty overrides for a task that sets none', function () {
    $task = app(Schedule::class)->command('backup:run')->daily();

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0]['overrides'])->toBe([
        'max_runtime' => null,
        'grace' => null,
    ]);
});

it('keeps the schedule chainable after the overrides', function () {
    $task = app(Schedule::class)->command('backup:run')->pingpong(grace: 5)->dailyAt('04:00');

    event(new ScheduledTaskStarting($task));

    expect(sentCheckIns()[0])->toMatchArray([
        'cron' => '0 4 * * *',
        'overrides' => ['max_runtime' => null, 'grace' => 5],
    ]);
});
