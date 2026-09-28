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
