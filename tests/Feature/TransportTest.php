<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Transport;

it('posts the payload with the bearer key, schema and server to the production endpoint by default', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => Http::response()]);

    $delivered = app(Transport::class)->send('api/agent/tick', ['agent_version' => '0.1.0']);

    expect($delivered)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer pp_agent_test')
        && $request->data() === [
            'schema' => 1,
            'server' => gethostname(),
            'agent_version' => '0.1.0',
        ]);
});

it('posts to the configured endpoint', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.endpoint', 'https://pingpong.test/');

    Http::fake(['https://pingpong.test/api/agent/tick' => Http::response()]);

    app(Transport::class)->send('api/agent/tick', []);

    Http::assertSentCount(1);
});

it('sends nothing without a key', function () {
    config()->set('pingpong-agent.key', null);

    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::response()]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();

    Http::assertNothingSent();
});

it('sends nothing when disabled', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.enabled', false);

    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::response()]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();

    Http::assertNothingSent();
});

it('sends nothing while the app runs its unit tests, even with a key', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    app()['env'] = 'testing';

    Http::fake(['https://pingpong.kobaltdigital.nl/*' => Http::response()]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();

    Http::assertNothingSent();
});

it('gives up after 5 seconds', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $timeout = null;

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => function (Request $request, array $options) use (&$timeout) {
        $timeout = $options['timeout'];

        return Http::response();
    }]);

    app(Transport::class)->send('api/agent/tick', []);

    expect($timeout)->toBe(5);
});

it('swallows a connection error', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();
});

it('retries a DNS failure once with the same check-in payload', function (string $error) {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $attempts = [];

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/check-in' => function (Request $request, array $options) use (&$attempts, $error) {
        $attempts[] = ['payload' => $request->data(), 'options' => $options];

        if (count($attempts) === 1) {
            throw new ConnectionException($error);
        }

        return Http::response();
    }]);

    $delivered = app(Transport::class)->send('api/agent/check-in', [
        'signal' => 'success',
        'run_id' => 'same-run',
    ]);

    expect($delivered)->toBeTrue()
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[1]['payload'])->toBe($attempts[0]['payload'])
        ->and($attempts[0]['options']['connect_timeout'])->toBe(2)
        ->and($attempts[1]['options']['timeout'])->toBeLessThanOrEqual(5);
})->with([
    'resolver timeout' => 'cURL error 28: Resolving timed out after 2001 milliseconds',
    'host not found' => 'cURL error 6: Could not resolve host: pingpong.kobaltdigital.nl',
]);

it('stops after two failed DNS attempts without throwing', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $attempts = 0;

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/check-in' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Resolving timed out');
    }]);

    expect(app(Transport::class)->send('api/agent/check-in', ['run_id' => 'same-run']))->toBeFalse()
        ->and($attempts)->toBe(2);
});

it('does not retry a timeout after connecting because the payload may have arrived', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $attempts = 0;

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds');
    }]);

    expect(app(Transport::class)->send('api/agent/tick', []))->toBeFalse()
        ->and($attempts)->toBe(1);
});

it('subtracts time spent resolving from the retry timeout', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $timeouts = [];

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/check-in' => function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];

        if (count($timeouts) === 1) {
            usleep(20_000);

            throw new ConnectionException('cURL error 6: Could not resolve host');
        }

        return Http::response();
    }]);

    expect(app(Transport::class)->send('api/agent/check-in', []))->toBeTrue()
        ->and($timeouts[0])->toBe(5)
        ->and($timeouts[1])->toBeGreaterThan(0)
        ->and($timeouts[1])->toBeLessThan(4.99);
});

it('does not retry a DNS failure after the request budget is exhausted', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $attempts = 0;

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/check-in' => function () use (&$attempts) {
        $attempts++;
        usleep(5_010_000);

        throw new ConnectionException('cURL error 28: Resolving timed out after 5001 milliseconds');
    }]);

    expect(app(Transport::class)->send('api/agent/check-in', []))->toBeFalse()
        ->and($attempts)->toBe(1);
});

it('swallows a server error', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => Http::response(status: 500)]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();
});

it('skips without retrying when rate limited', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => Http::response(status: 429, headers: ['Retry-After' => '30'])]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();

    Http::assertSentCount(1);
});

it('follows a redirected endpoint with the POST, body and key intact, and says which endpoint to set', function (int $status) {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.endpoint', 'http://pingpong.test');

    Log::spy();

    Http::fake([
        'http://pingpong.test/api/agent/tick' => Http::response(status: $status, headers: ['Location' => 'https://pingpong.test/api/agent/tick']),
        'https://pingpong.test/api/agent/tick' => Http::response(),
    ]);

    $delivered = app(Transport::class)->send('api/agent/tick', ['agent_version' => '0.1.0']);

    expect($delivered)->toBeTrue();

    Http::assertSentCount(2);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pingpong.test/api/agent/tick'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer pp_agent_test')
        && $request->data() === [
            'schema' => 1,
            'server' => gethostname(),
            'agent_version' => '0.1.0',
        ]);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $message === 'PingPong endpoint redirected to https://pingpong.test; set PINGPONG_ENDPOINT to that URL (usually https).');
})->with([301, 302, 307, 308]);

it('does not hand the key to a redirect onto another host', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.endpoint', 'http://pingpong.test');

    Http::fake([
        'http://pingpong.test/api/agent/tick' => Http::response(status: 301, headers: ['Location' => 'https://elsewhere.test/api/agent/tick']),
        'https://elsewhere.test/api/agent/tick' => Http::response(),
    ]);

    app(Transport::class)->send('api/agent/tick', []);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://elsewhere.test/api/agent/tick'
        && ! $request->hasHeader('Authorization'));
});

it('gives up without throwing after 3 redirects', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.endpoint', 'https://pingpong.test');

    Log::spy();

    Http::fake(['https://pingpong.test/*' => Http::response(status: 301, headers: ['Location' => 'https://pingpong.test/api/agent/tick'])]);

    $delivered = app(Transport::class)->send('api/agent/tick', []);

    expect($delivered)->toBeFalse();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'could not reach')
        && str_contains($context['exception'], 'Will not follow more than 3 redirects'));
});
