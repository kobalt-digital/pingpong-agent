<?php

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Actions\FlushOutbox;
use KobaltDigital\PingPong\Actions\SendTick;
use KobaltDigital\PingPong\Delivery\Outbox;
use KobaltDigital\PingPong\Jobs\ReportQueueCanary;
use KobaltDigital\PingPong\Transport;

const OUTBOX_CHECK_IN_URL = 'https://pingpong.kobaltdigital.nl/api/agent/check-in';
const OUTBOX_TICK_URL = 'https://pingpong.kobaltdigital.nl/api/agent/tick';

beforeEach(function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
});

/**
 * @return array<int, array<string, mixed>>
 */
function queuedCheckIns(): array
{
    return array_column(app(Outbox::class)->oldest(Outbox::MAX_ENTRIES), 'payload');
}

/**
 * @return array<int, array<string, mixed>>
 */
function receivedCheckIns(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === OUTBOX_CHECK_IN_URL && $pair[1]?->successful())
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

/**
 * @return array<string, mixed>
 */
function checkInPayload(string $runId, string $signal = 'start'): array
{
    return [
        'slug' => 'health-check',
        'signal' => $signal,
        'run_id' => $runId,
        'cron' => '* * * * *',
        'timezone' => 'UTC',
        'overrides' => ['max_runtime' => null, 'grace' => null],
        'exit_code' => null,
        'message' => null,
        'occurred_at' => '2026-11-12T09:42:07.250Z',
    ];
}

function outboxDirectory(): string
{
    return storage_path('framework/pingpong-agent/'.gethostname());
}

function runTask(): void
{
    $task = app(Schedule::class)->command('health:check')->everyMinute();

    event(new ScheduledTaskStarting($task));

    $task->exitCode = 0;

    event(new ScheduledTaskFinished($task, 0.5));
}

it('keeps a Check-in that did not reach PingPong, with its run id and the time it happened', function () {
    Http::fake([OUTBOX_CHECK_IN_URL => Http::failedConnection('cURL error 28: Resolving timed out after 5000 milliseconds')]);

    Date::setTestNow(Date::parse('2026-11-12 09:42:07.250', 'UTC'));

    runTask();

    [$start, $success] = queuedCheckIns();

    expect(queuedCheckIns())->toHaveCount(2)
        ->and($start)->toMatchArray(['slug' => 'health-check', 'signal' => 'start', 'occurred_at' => '2026-11-12T09:42:07.250Z'])
        ->and($success)->toMatchArray(['signal' => 'success', 'run_id' => $start['run_id']]);
});

it('keeps a Check-in PingPong failed to handle or rate limited', function (int $status) {
    Http::fake([OUTBOX_CHECK_IN_URL => Http::response(status: $status)]);

    runTask();

    expect(queuedCheckIns())->toHaveCount(2);
})->with([500, 502, 503, 429]);

it('drops a Check-in PingPong rejected, as before', function (int $status) {
    Http::fake([OUTBOX_CHECK_IN_URL => Http::response(status: $status)]);

    runTask();

    expect(queuedCheckIns())->toBeEmpty();
})->with([400, 401, 403, 404, 422]);

it('keeps nothing that was delivered', function () {
    Http::fake([OUTBOX_CHECK_IN_URL => Http::response()]);

    runTask();

    expect(queuedCheckIns())->toBeEmpty();
});

it('keeps only Check-ins, not ticks, canaries or handshakes', function () {
    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::failedConnection()]);

    app(SendTick::class)->execute();
    app(Transport::class)->send('api/agent/handshake', ['agent_version' => '0.1.0']);
    (new ReportQueueCanary('9b1f5e0c-3f4e-4a53-9a57-7f1f2c7e8d10', '2026-11-12T09:42:00.000Z', 'web-01'))->handle(app(Transport::class));

    expect(queuedCheckIns())->toBeEmpty();
});

it('replays kept Check-ins unchanged and oldest first right after the next delivered tick', function () {
    Http::fake([
        OUTBOX_CHECK_IN_URL => Http::sequence()
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->pushStatus(200)
            ->pushStatus(200),
        OUTBOX_TICK_URL => Http::response(),
    ]);

    Date::setTestNow(Date::parse('2026-11-12 09:42:07.250', 'UTC'));

    runTask();

    $undelivered = Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === OUTBOX_CHECK_IN_URL)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();

    Date::setTestNow(Date::parse('2026-11-12 09:59:21', 'UTC'));

    app(SendTick::class)->execute();

    expect(receivedCheckIns())->toBe($undelivered)
        ->and(array_column(receivedCheckIns(), 'signal'))->toBe(['start', 'success'])
        ->and(receivedCheckIns()[0]['occurred_at'])->toBe('2026-11-12T09:42:07.250Z')
        ->and(queuedCheckIns())->toBeEmpty();
});

it('replays nothing while the tick does not get through', function () {
    app(Outbox::class)->push(checkInPayload('run-1'));

    Http::fake([
        OUTBOX_CHECK_IN_URL => Http::response(),
        OUTBOX_TICK_URL => Http::failedConnection(),
    ]);

    app(SendTick::class)->execute();

    Http::assertNotSent(fn ($request) => $request->url() === OUTBOX_CHECK_IN_URL);

    expect(queuedCheckIns())->toHaveCount(1);
});

it('stops replaying at the first Check-in that does not get through, and keeps it and the rest in order', function () {
    foreach (['run-1', 'run-2', 'run-3', 'run-4'] as $runId) {
        app(Outbox::class)->push(checkInPayload($runId));
    }

    Http::fake([OUTBOX_CHECK_IN_URL => Http::sequence()
        ->pushStatus(200)
        ->pushStatus(503)
        ->pushStatus(200)]);

    app(FlushOutbox::class)->execute();

    Http::assertSentCount(2);

    expect(array_column(queuedCheckIns(), 'run_id'))->toBe(['run-2', 'run-3', 'run-4']);
});

it('drops a replayed Check-in PingPong rejects and goes on with the next', function () {
    app(Outbox::class)->push(checkInPayload('run-1'));
    app(Outbox::class)->push(checkInPayload('run-2'));

    Http::fake([OUTBOX_CHECK_IN_URL => Http::sequence()
        ->pushStatus(422)
        ->pushStatus(200)]);

    app(FlushOutbox::class)->execute();

    Http::assertSentCount(2);

    expect(queuedCheckIns())->toBeEmpty();
});

it('replays at most 50 Check-ins per tick', function () {
    foreach (range(1, 60) as $run) {
        app(Outbox::class)->push(checkInPayload("run-{$run}"));
    }

    Http::fake([OUTBOX_CHECK_IN_URL => Http::response()]);

    app(FlushOutbox::class)->execute();

    Http::assertSentCount(50);

    expect(array_column(queuedCheckIns(), 'run_id'))->toBe(array_map(fn (int $run) => "run-{$run}", range(51, 60)));
});

it('stops replaying after 10 seconds, so the tick never runs long', function () {
    foreach (range(1, 5) as $run) {
        app(Outbox::class)->push(checkInPayload("run-{$run}"));
    }

    Date::setTestNow(Date::parse('2026-11-12 10:00:00', 'UTC'));

    Http::fake([OUTBOX_CHECK_IN_URL => function () {
        Date::setTestNow(Date::now()->addSeconds(4));

        return Http::response();
    }]);

    app(FlushOutbox::class)->execute();

    Http::assertSentCount(3);

    expect(queuedCheckIns())->toHaveCount(2);
});

it('keeps at most 500 Check-ins, dropping the oldest, and logs it once', function () {
    Log::spy();

    foreach (range(1, 503) as $run) {
        app(Outbox::class)->push(checkInPayload("run-{$run}"));
    }

    $runIds = array_column(queuedCheckIns(), 'run_id');

    expect($runIds)->toHaveCount(500)
        ->and($runIds[0])->toBe('run-4')
        ->and(end($runIds))->toBe('run-503');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'dropped'));
});

it('drops Check-ins older than 24 hours, also when replaying', function () {
    Log::spy();

    Date::setTestNow(Date::parse('2026-11-11 09:00:00', 'UTC'));
    app(Outbox::class)->push(checkInPayload('run-old'));

    Date::setTestNow(Date::parse('2026-11-11 10:00:00', 'UTC'));
    app(Outbox::class)->push(checkInPayload('run-new'));

    Date::setTestNow(Date::parse('2026-11-12 09:30:00', 'UTC'));

    Http::fake([OUTBOX_CHECK_IN_URL => Http::response()]);

    app(FlushOutbox::class)->execute();

    expect(array_column(receivedCheckIns(), 'run_id'))->toBe(['run-new']);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'dropped'));
});

it('logs dropping when every kept Check-in expired', function () {
    Log::spy();

    Date::setTestNow(Date::parse('2026-11-11 09:00:00', 'UTC'));
    app(Outbox::class)->push(checkInPayload('run-old'));

    Date::setTestNow(Date::parse('2026-11-12 09:30:00', 'UTC'));

    Http::fake([OUTBOX_CHECK_IN_URL => Http::response()]);

    app(FlushOutbox::class)->execute();

    Http::assertNothingSent();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'dropped'));
});

it('logs dropping again only after the outbox emptied', function () {
    Log::spy();

    foreach (range(1, 502) as $run) {
        app(Outbox::class)->push(checkInPayload("run-{$run}"));
    }

    Http::fake([OUTBOX_CHECK_IN_URL => Http::response()]);

    foreach (range(1, 10) as $tick) {
        app(FlushOutbox::class)->execute();
    }

    foreach (range(1, 501) as $run) {
        app(Outbox::class)->push(checkInPayload("run-again-{$run}"));
    }

    Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message) => str_contains($message, 'dropped'));
});

it('tells PingPong in every tick how many Check-ins wait in the outbox, counted before this tick replays any', function () {
    foreach (['run-1', 'run-2', 'run-3'] as $runId) {
        app(Outbox::class)->push(checkInPayload($runId));
    }

    Http::fake([
        OUTBOX_TICK_URL => Http::response(),
        OUTBOX_CHECK_IN_URL => Http::response(),
    ]);

    app(SendTick::class)->execute();
    app(SendTick::class)->execute();

    $ticks = Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === OUTBOX_TICK_URL)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values();

    expect($ticks[0]['outbox_pending'])->toBe(3)
        ->and($ticks[1]['outbox_pending'])->toBe(0)
        ->and(queuedCheckIns())->toBeEmpty();
});

it('reports an outbox that cannot be read as empty, without failing the tick', function () {
    mkdir(outboxDirectory(), 0775, true);
    file_put_contents(outboxDirectory().'/outbox.json', '{"entries": [');

    Http::fake([OUTBOX_TICK_URL => Http::response()]);

    expect(app(SendTick::class)->execute())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === OUTBOX_TICK_URL && $request['outbox_pending'] === 0);
});

it('reports an outbox it is not allowed to read as empty, without failing the tick', function () {
    app(Outbox::class)->push(checkInPayload('run-1'));

    chmod(outboxDirectory().'/outbox.json', 0000);

    Http::fake([OUTBOX_TICK_URL => Http::response()]);

    expect(app(SendTick::class)->execute())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === OUTBOX_TICK_URL && $request['outbox_pending'] === 0);

    chmod(outboxDirectory().'/outbox.json', 0664);
})->skipOnWindows();

it('keeps the outbox in a file per server under storage', function () {
    app(Outbox::class)->push(checkInPayload('run-1'));

    expect(outboxDirectory().'/outbox.json')->toBeFile()
        ->and(array_column((new Outbox)->oldest(10), 'payload'))->toBe([checkInPayload('run-1')]);
});

it('never throws when the outbox cannot be written', function () {
    useUnwritableStorage();

    app(Outbox::class)->push(checkInPayload('run-1'));

    expect(app(Outbox::class)->pending())->toBe(0);
});
