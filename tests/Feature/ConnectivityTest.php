<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\Actions\SendTick;
use KobaltDigital\PingPong\Delivery\Connectivity;
use KobaltDigital\PingPong\Delivery\Failure;
use KobaltDigital\PingPong\Delivery\FailureKind;
use KobaltDigital\PingPong\Transport;

const CONNECTIVITY_TICK_URL = 'https://pingpong.kobaltdigital.nl/api/agent/tick';

beforeEach(function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
});

/**
 * @return array<int, array<string, mixed>>
 */
function connectivityTicks(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === CONNECTIVITY_TICK_URL)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

it('leaves connectivity out of a tick when PingPong was reachable all along', function () {
    Http::fake([CONNECTIVITY_TICK_URL => Http::response()]);

    app(SendTick::class)->execute();

    expect(connectivityTicks()[0])->not->toHaveKey('connectivity');
});

it('tells PingPong in the first delivered tick how long it was unreachable and why', function () {
    Http::fake([
        'https://pingpong.kobaltdigital.nl/api/agent/check-in' => Http::failedConnection('cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received'),
        CONNECTIVITY_TICK_URL => Http::sequence()
            ->pushFailedConnection('cURL error 28: Resolving timed out after 5000 milliseconds')
            ->pushStatus(200),
    ]);

    Date::setTestNow(Date::parse('2026-11-12 09:42:07', 'UTC'));
    app(Transport::class)->send('api/agent/check-in', []);

    Date::setTestNow(Date::parse('2026-11-12 09:59:21', 'UTC'));
    app(SendTick::class)->execute();

    Date::setTestNow(Date::parse('2026-11-12 10:00:00', 'UTC'));
    app(SendTick::class)->execute();

    expect(connectivityTicks()[1]['connectivity'])->toBe([
        'unreachable_since' => '2026-11-12T09:42:07Z',
        'unreachable_until' => '2026-11-12T09:59:21Z',
        'failed_attempts' => 2,
        'last_error' => 'dns',
        'last_error_message' => 'cURL error 28: Resolving timed out after 5000 milliseconds',
    ]);
});

it('stops reporting the gap once PingPong accepted the tick that carried it', function () {
    Http::fake([CONNECTIVITY_TICK_URL => Http::sequence()
        ->pushFailedConnection()
        ->pushStatus(200)
        ->pushStatus(200)]);

    app(SendTick::class)->execute();
    app(SendTick::class)->execute();
    app(SendTick::class)->execute();

    $ticks = connectivityTicks();

    expect($ticks[1])->toHaveKey('connectivity')
        ->and($ticks[2])->not->toHaveKey('connectivity')
        ->and(app(Connectivity::class)->report())->toBeNull();
});

it('keeps counting when the tick that carries the gap fails too', function () {
    Http::fake([CONNECTIVITY_TICK_URL => Http::sequence()
        ->pushFailedConnection()
        ->pushStatus(503)
        ->pushStatus(200)]);

    Date::setTestNow(Date::parse('2026-11-12 09:42:07', 'UTC'));
    app(SendTick::class)->execute();

    Date::setTestNow(Date::parse('2026-11-12 09:43:07', 'UTC'));
    app(SendTick::class)->execute();

    Date::setTestNow(Date::parse('2026-11-12 09:44:07', 'UTC'));
    app(SendTick::class)->execute();

    expect(connectivityTicks()[2]['connectivity'])->toMatchArray([
        'unreachable_since' => '2026-11-12T09:42:07Z',
        'unreachable_until' => '2026-11-12T09:43:07Z',
        'failed_attempts' => 2,
        'last_error' => 'http_5xx',
        'last_error_message' => 'HTTP 503 Service Unavailable',
    ]);
});

it('counts failures to reach any endpoint', function (string $path) {
    Http::fake([
        CONNECTIVITY_TICK_URL => Http::response(),
        'https://pingpong.kobaltdigital.nl/*' => Http::failedConnection('cURL error 7: Failed to connect'),
    ]);

    app(Transport::class)->send($path, []);

    app(SendTick::class)->execute();

    expect(connectivityTicks()[0]['connectivity'])->toMatchArray([
        'failed_attempts' => 1,
        'last_error' => 'connect',
    ]);
})->with(['api/agent/check-in', 'api/agent/handshake', 'api/agent/canary']);

it('counts a rate limited request', function () {
    Http::fake([
        CONNECTIVITY_TICK_URL => Http::response(),
        'https://pingpong.kobaltdigital.nl/*' => Http::response(status: 429),
    ]);

    app(Transport::class)->send('api/agent/check-in', []);

    expect(app(Connectivity::class)->report())->toMatchArray(['last_error' => 'rate_limited']);
});

it('does not count a request PingPong rejected, since it reached PingPong', function (int $status) {
    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::response(status: $status)]);

    app(Transport::class)->send('api/agent/check-in', []);

    expect(app(Connectivity::class)->report())->toBeNull();
})->with([401, 403, 404, 422]);

it('keeps a failure that happened while the tick carrying the gap was underway', function () {
    Date::setTestNow(Date::parse('2026-11-12 09:42:07', 'UTC'));
    app(Connectivity::class)->recordFailure(new Failure(FailureKind::DNS, 'cURL error 6: Could not resolve host'));

    Http::fake([CONNECTIVITY_TICK_URL => function () {
        Date::setTestNow(Date::parse('2026-11-12 09:59:30', 'UTC'));

        app(Connectivity::class)->recordFailure(new Failure(FailureKind::TIMEOUT, 'cURL error 28: Operation timed out'));

        return Http::response();
    }]);

    app(SendTick::class)->execute();

    expect(app(Connectivity::class)->report())->toBe([
        'unreachable_since' => '2026-11-12T09:59:30Z',
        'unreachable_until' => '2026-11-12T09:59:30Z',
        'failed_attempts' => 1,
        'last_error' => 'timeout',
        'last_error_message' => 'cURL error 28: Operation timed out',
    ]);
});

it('never reports the gap ending before it started, when the clock went back', function () {
    Date::setTestNow(Date::parse('2026-11-12 09:42:07', 'UTC'));
    app(Connectivity::class)->recordFailure(new Failure(FailureKind::DNS, 'cURL error 6: Could not resolve host'));

    Date::setTestNow(Date::parse('2026-11-12 09:41:00', 'UTC'));
    app(Connectivity::class)->recordFailure(new Failure(FailureKind::DNS, 'cURL error 6: Could not resolve host'));

    expect(app(Connectivity::class)->report())->toMatchArray([
        'unreachable_since' => '2026-11-12T09:42:07Z',
        'unreachable_until' => '2026-11-12T09:42:07Z',
        'failed_attempts' => 2,
    ]);
});

it('keeps the state in a file per server under storage', function () {
    app(Connectivity::class)->recordFailure(new Failure(FailureKind::CONNECT, 'cURL error 7: Failed to connect'));

    expect(storage_path('framework/pingpong-agent/'.gethostname().'/connectivity.json'))->toBeFile()
        ->and((new Connectivity)->report())->toMatchArray(['failed_attempts' => 1]);
});

it('never throws when the state cannot be stored', function () {
    useUnwritableStorage();

    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::failedConnection('cURL error 7: Failed to connect')]);

    expect(app(Transport::class)->send('api/agent/check-in', []))->toBeFalse()
        ->and(app(SendTick::class)->execute())->toBeFalse();
});

it('sends the connectivity report in the shape of the fixture', function () {
    app(Connectivity::class)->recordFailure(new Failure(FailureKind::DNS, 'cURL error 28: Resolving timed out after 5000 milliseconds'));

    Http::fake([CONNECTIVITY_TICK_URL => Http::response()]);

    app(SendTick::class)->execute();

    Http::assertSent(fn (Request $request) => payloadShape(json_decode($request->body())->connectivity) === payloadShape(payloadFixture('tick')->connectivity));
});
