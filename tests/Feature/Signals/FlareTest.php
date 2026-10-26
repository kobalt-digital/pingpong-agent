<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\InstalledPackages;
use KobaltDigital\PingPong\Signals\Flare;
use KobaltDigital\PingPong\Tests\Fakes\FakeInstalledPackages;

const FLARE_INGRESS = 'https://ingress.flareapp.io/v1/errors';
const FLARE_IGNITION_REPORTS = 'https://flareapp.io/api/reports';
const FLARE_DAEMON_HEALTH = 'http://127.0.0.1:8787/health';

function installFlare(array $versions = ['spatie/laravel-flare' => '3.2.1']): void
{
    app()->instance(InstalledPackages::class, new FakeInstalledPackages($versions));
}

/**
 * What `Flare::handles($exceptions)` registers in bootstrap/app.php: a
 * closure returning the Flare client.
 */
function registerFlareHandler(): void
{
    app(ExceptionHandler::class)->reportable(fn (Throwable $exception): ?\Spatie\FlareClient\Flare => null);
}

function flareSignal(): ?array
{
    return app(Flare::class)->collect();
}

beforeEach(function () {
    $this->freezeSecond();

    config()->set('flare.key', 'flare_test_key');
});

it('is left out when no Flare client is installed, without asking Flare', function () {
    installFlare([]);
    Http::fake();

    expect(flareSignal())->toBeNull();

    Http::assertNothingSent();
});

it('reports that laravel-flare would report an exception', function () {
    installFlare();
    registerFlareHandler();
    config()->set('flare.sender.class', 'Spatie\FlareClient\Senders\DaemonSender');

    Http::fake([
        FLARE_DAEMON_HEALTH => Http::response(['status' => 'ok']),
        FLARE_INGRESS => Http::response([], 201),
    ]);

    expect(flareSignal())->toBe([
        'client' => 'spatie/laravel-flare',
        'client_version' => '3.2.1',
        'key_set' => true,
        'key_hash' => substr(hash('sha256', 'flare_test_key'), 0, 8),
        'report' => true,
        'handler_wired' => true,
        'sender' => 'daemon',
        'daemon_reachable' => true,
        'key_accepted' => true,
        'key_status' => 201,
        'key_checked_at' => now()->toIso8601String(),
        'error' => null,
    ]);

    Http::assertSent(fn (Request $request) => $request->url() === FLARE_INGRESS
        && $request->method() === 'POST'
        && $request->hasHeader('x-api-token', 'flare_test_key')
        && $request->body() === '[]');
});

it('sends nothing to Flare without a key', function () {
    installFlare();
    config()->set('flare.key', null);
    Http::fake();

    expect(flareSignal())
        ->key_set->toBeFalse()
        ->key_hash->toBeNull()
        ->key_accepted->toBeNull()
        ->key_status->toBeNull()
        ->key_checked_at->toBeNull();

    Http::assertNothingSent();
});

it('reports a key Flare rejects', function (int $status) {
    installFlare();
    Http::fake([FLARE_INGRESS => Http::response(['message' => 'Invalid API key'], $status)]);

    expect(flareSignal())
        ->key_accepted->toBeFalse()
        ->key_status->toBe($status)
        ->error->toBeNull();
})->with([401, 403]);

it('cannot tell when Flare fails otherwise', function () {
    installFlare();
    Http::fake([FLARE_INGRESS => Http::response('', 503)]);

    expect(flareSignal())
        ->key_accepted->toBeNull()
        ->key_status->toBe(503)
        ->error->toBe('Flare answered the key check with HTTP 503');
});

it('cannot tell when Flare cannot be reached', function () {
    installFlare();
    Http::fake([FLARE_INGRESS => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    expect(flareSignal())
        ->key_accepted->toBeNull()
        ->key_status->toBeNull()
        ->error->toBe('Flare could not be reached: cURL error 28: Operation timed out');
});

it('asks Flare about the key once an hour', function () {
    installFlare();
    Http::fake([FLARE_INGRESS => Http::response([], 201)]);

    flareSignal();
    $this->travel(59)->minutes();
    $signal = flareSignal();

    Http::assertSentCount(1);
    expect($signal['key_checked_at'])->toBe(now()->subMinutes(59)->toIso8601String());

    $this->travel(2)->minutes();
    flareSignal();

    Http::assertSentCount(2);
});

it('asks Flare again right away when the key changes', function () {
    installFlare();
    Http::fake([FLARE_INGRESS => Http::sequence()->push([], 201)->push(['message' => 'Invalid API key'], 403)]);

    expect(flareSignal()['key_accepted'])->toBeTrue();

    config()->set('flare.key', 'another_key');

    expect(flareSignal())
        ->key_accepted->toBeFalse()
        ->key_hash->toBe(substr(hash('sha256', 'another_key'), 0, 8));

    Http::assertSentCount(2);
});

it('reports a handler that does not hand exceptions to Flare', function () {
    installFlare();
    Http::fake([FLARE_INGRESS => Http::response([], 201)]);

    app(ExceptionHandler::class)->reportable(fn (Throwable $exception) => null);

    expect(flareSignal()['handler_wired'])->toBeFalse();
});

it('reports reporting switched off', function () {
    installFlare();
    config()->set('flare.report', false);
    Http::fake([FLARE_INGRESS => Http::response([], 201)]);

    expect(flareSignal()['report'])->toBeFalse();
});

it('reports a daemon that does not answer', function () {
    installFlare();
    config()->set('flare.sender.class', 'Spatie\FlareClient\Senders\DaemonSender');
    config()->set('flare.sender.config.daemon_url', 'http://10.0.0.5:9000/');

    Http::fake([
        'http://10.0.0.5:9000/health' => fn () => throw new ConnectionException('Connection refused'),
        FLARE_INGRESS => Http::response([], 201),
    ]);

    expect(flareSignal())
        ->sender->toBe('daemon')
        ->daemon_reachable->toBeFalse();
});

it('does not look for a daemon when sending straight to Flare', function () {
    installFlare();
    config()->set('flare.sender.class', 'Spatie\FlareClient\Senders\CurlSender');
    Http::fake([FLARE_INGRESS => Http::response([], 201)]);

    expect(flareSignal())
        ->sender->toBe('direct')
        ->daemon_reachable->toBeNull();

    Http::assertSentCount(1);
});

it('asks the base url the app configured', function () {
    installFlare();
    config()->set('flare.base_url', 'https://flare.example.test/');
    Http::fake(['https://flare.example.test/v1/errors' => Http::response([], 201)]);

    expect(flareSignal()['key_accepted'])->toBeTrue();
});

it('reports Ignition, which reports through the log', function (array $channels, bool $reachesFlare) {
    installFlare(['spatie/laravel-ignition' => '2.12.0']);
    config()->set('logging.default', 'stack');
    config()->set('logging.channels', $channels);
    Http::fake([FLARE_IGNITION_REPORTS => Http::response([], 201)]);

    expect(flareSignal())
        ->client->toBe('spatie/laravel-ignition')
        ->client_version->toBe('2.12.0')
        ->report->toBeNull()
        ->sender->toBeNull()
        ->daemon_reachable->toBeNull()
        ->handler_wired->toBe($reachesFlare)
        ->key_accepted->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === FLARE_IGNITION_REPORTS
        && $request->hasHeader('x-api-token', 'flare_test_key'));
})->with([
    'flare in the stack' => [[
        'stack' => ['driver' => 'stack', 'channels' => ['single', 'flare']],
        'single' => ['driver' => 'single'],
        'flare' => ['driver' => 'flare'],
    ], true],
    'flare in a nested stack' => [[
        'stack' => ['driver' => 'stack', 'channels' => 'single,errors'],
        'errors' => ['driver' => 'stack', 'channels' => ['bugs']],
        'bugs' => ['driver' => 'flare'],
        'single' => ['driver' => 'single'],
    ], true],
    'flare defined but not in the stack' => [[
        'stack' => ['driver' => 'stack', 'channels' => ['single']],
        'single' => ['driver' => 'single'],
        'flare' => ['driver' => 'flare'],
    ], false],
    'stacks that lead to each other' => [[
        'stack' => ['driver' => 'stack', 'channels' => ['other']],
        'other' => ['driver' => 'stack', 'channels' => ['stack']],
    ], false],
]);

it('prefers laravel-flare when Ignition is installed next to it', function () {
    installFlare(['spatie/laravel-flare' => '3.2.1', 'spatie/laravel-ignition' => '2.12.0']);
    Http::fake([FLARE_INGRESS => Http::response([], 201)]);

    expect(flareSignal()['client'])->toBe('spatie/laravel-flare');
});
