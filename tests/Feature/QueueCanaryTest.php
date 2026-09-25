<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\Jobs\ReportQueueCanary;
use KobaltDigital\PingPong\Signals\QueueSignal;

const CANARY_URL = 'https://pingpong.kobaltdigital.nl/api/agent/canary';

it('dispatches no canary on the sync queue', function () {
    Bus::fake();

    $signal = app(QueueSignal::class)->collect();

    Bus::assertNothingDispatched();

    expect($signal)->toBe([
        'connection' => 'sync',
        'driver' => 'sync',
        'canary_id' => null,
        'error' => null,
    ]);
});

it('dispatches no canary to a queue that runs without a worker', function (string $driver) {
    config()->set('queue.connections.inline', ['driver' => $driver]);
    config()->set('queue.default', 'inline');

    Bus::fake();

    $signal = app(QueueSignal::class)->collect();

    Bus::assertNothingDispatched();

    expect($signal['driver'])->toBe($driver)
        ->and($signal['canary_id'])->toBeNull();
})->with(['deferred', 'background', 'null']);

it('dispatches a canary to a queue with workers', function () {
    useDatabaseQueue();

    Bus::fake();

    $signal = app(QueueSignal::class)->collect();

    Bus::assertDispatchedTimes(ReportQueueCanary::class, 1);
    Bus::assertDispatched(ReportQueueCanary::class, fn (ReportQueueCanary $canary) => $canary->canaryId === $signal['canary_id']);

    expect($signal['connection'])->toBe('database')
        ->and($signal['driver'])->toBe('database')
        ->and($signal['canary_id'])->toBeString()->not->toBeEmpty()
        ->and($signal['error'])->toBeNull();
});

it('reports a queue it cannot dispatch to as a fact', function () {
    config()->set('queue.default', 'database');

    $signal = app(QueueSignal::class)->collect();

    expect($signal['canary_id'])->toBeNull()
        ->and($signal['error'])->toContain('jobs');
});

it('reports the canary back when a worker runs it', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    useDatabaseQueue();

    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::response()]);

    $this->travelTo('2026-09-25 12:00:00.250');

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertSentCount(1);

    $this->travelTo('2026-09-25 12:00:03.500');

    $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

    $tick = Http::recorded()->first()[0];

    Http::assertSent(fn (Request $request) => $request->url() === CANARY_URL
        && payloadShape(json_decode($request->body())) === payloadShape(payloadFixture('canary'))
        && $request['canary_id'] === $tick['signals']['queue']['canary_id']
        && $request['dispatched_at'] === '2026-09-25T12:00:00.250Z'
        && $request['dispatched_from'] === gethostname()
        && $request['ran_at'] === '2026-09-25T12:00:03.500Z');
});

it('reports the canary back once, even when PingPong is down', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    useDatabaseQueue();

    Http::fake([
        'https://pingpong.kobaltdigital.nl/api/agent/tick' => Http::response(),
        CANARY_URL => Http::response(status: 500),
    ]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();
    $this->artisan('queue:work', ['--once' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertSentCount(2);

    expect(DB::table('jobs')->count())->toBe(0);
});

it('is tried once and never retried', function () {
    $canary = new ReportQueueCanary('9b1f5e0c-3f4e-4a53-9a57-7f1f2c7e8d10', '2026-09-25T12:00:00.250Z', 'web-01');

    expect($canary->tries)->toBe(1);
});
