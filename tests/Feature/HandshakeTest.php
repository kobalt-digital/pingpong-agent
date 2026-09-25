<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\AgentVersion;

const HANDSHAKE_URL = 'https://pingpong.kobaltdigital.nl/api/agent/handshake';

function runningAgentVersion(string $version): void
{
    test()->mock(AgentVersion::class)->shouldReceive('current')->andReturn($version);
}

it('sends no handshake without a key', function () {
    config()->set('pingpong-agent.key', null);

    Http::fake([HANDSHAKE_URL => Http::response()]);

    app()->terminate();

    Http::assertNothingSent();
});

it('sends no handshake while the app runs its unit tests', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    app()['env'] = 'testing';

    Http::fake([HANDSHAKE_URL => Http::response()]);

    app()->terminate();

    Http::assertNothingSent();
});

it('sends the handshake when the app terminates, not while it boots', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake([HANDSHAKE_URL => Http::response()]);

    Http::assertNothingSent();

    app()->terminate();

    Http::assertSentCount(1);
});

it('sends a handshake shaped like the schema 1 fixture', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    runningAgentVersion('0.1.0');

    Http::fake([HANDSHAKE_URL => Http::response()]);

    app()->terminate();

    Http::assertSent(function (Request $request) {
        $payload = json_decode($request->body());

        return $request->hasHeader('Authorization', 'Bearer pp_agent_test')
            && payloadShape($payload) === payloadShape(payloadFixture('handshake'))
            && $payload->agent_version === '0.1.0'
            && $payload->php_version === PHP_VERSION
            && $payload->laravel_version === app()->version();
    });
});

it('sends one handshake per agent version', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    runningAgentVersion('0.1.0');

    Http::fake([HANDSHAKE_URL => Http::response()]);

    app()->terminate();
    app()->terminate();

    Http::assertSentCount(1);
});

it('sends a new handshake after the agent is upgraded', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake([HANDSHAKE_URL => Http::response()]);

    runningAgentVersion('0.1.0');

    app()->terminate();

    runningAgentVersion('0.2.0');

    app()->terminate();

    Http::assertSentCount(2);
});

it('retries a failed handshake after ten minutes, not on the next request', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake([HANDSHAKE_URL => Http::sequence()->push(status: 500)->push()]);

    app()->terminate();
    app()->terminate();

    Http::assertSentCount(1);

    $this->travel(10)->minutes();

    app()->terminate();

    Http::assertSentCount(2);
});

it('does not throw when the handshake times out', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    Http::fake([HANDSHAKE_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    expect(fn () => app()->terminate())->not->toThrow(Exception::class);
});

it('does not throw when the cache is unavailable', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $this->mock(Repository::class)->shouldReceive('has')->andThrow(new RuntimeException('Connection refused'));

    Http::fake([HANDSHAKE_URL => Http::response()]);

    expect(fn () => app()->terminate())->not->toThrow(Exception::class);
});
