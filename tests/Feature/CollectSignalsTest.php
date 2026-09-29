<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Actions\CollectSignals;
use KobaltDigital\PingPong\Contracts\Collector;

class AlwaysOne implements Collector
{
    public function name(): string
    {
        return 'one';
    }

    public function collect(): ?array
    {
        return ['value' => 1];
    }
}

class AlwaysNull implements Collector
{
    public function name(): string
    {
        return 'nothing';
    }

    public function collect(): ?array
    {
        return null;
    }
}

class AlwaysThrows implements Collector
{
    public function name(): string
    {
        return 'broken';
    }

    public function collect(): ?array
    {
        throw new RuntimeException('disk on fire');
    }
}

class NameThrows implements Collector
{
    public function name(): string
    {
        throw new RuntimeException('no name');
    }

    public function collect(): ?array
    {
        return ['value' => 1];
    }
}

it('collects every configured collector by name', function () {
    config()->set('pingpong-agent.signals', [AlwaysOne::class]);

    expect(app(CollectSignals::class)->execute())->toBe(['one' => ['value' => 1]]);
});

it('omits a collector that returns null', function () {
    config()->set('pingpong-agent.signals', [AlwaysOne::class, AlwaysNull::class]);

    expect(app(CollectSignals::class)->execute())->toBe(['one' => ['value' => 1]]);
});

it('logs and skips a collector that throws, keeping the others', function () {
    config()->set('pingpong-agent.signals', [AlwaysThrows::class, AlwaysOne::class]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, AlwaysThrows::class)
            && $context['exception'] === 'disk on fire');

    expect(app(CollectSignals::class)->execute())->toBe(['one' => ['value' => 1]]);
});

it('logs and skips a collector whose name throws', function () {
    config()->set('pingpong-agent.signals', [NameThrows::class, AlwaysOne::class]);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, NameThrows::class)
            && $context['exception'] === 'no name');

    expect(app(CollectSignals::class)->execute())->toBe(['one' => ['value' => 1]]);
});

it('sends signals as an empty object when nothing was collected', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.signals', [AlwaysNull::class]);

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => Http::response()]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertSent(fn (Request $request) => str_contains($request->body(), '"signals":{}'));
});

it('sends collected signals in the tick', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
    config()->set('pingpong-agent.signals', [AlwaysOne::class]);

    Http::fake(['https://pingpong.kobaltdigital.nl/api/agent/tick' => Http::response()]);

    $this->artisan('pingpong:ping')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->data()['signals'] === ['one' => ['value' => 1]]);
});
