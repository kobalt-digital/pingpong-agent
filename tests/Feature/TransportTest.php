<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
