<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use KobaltDigital\PingPong\Signals\CacheSignal;

it('reports the cache as reachable with its latency', function () {
    $signal = app(CacheSignal::class)->collect();

    expect($signal['reachable'])->toBeTrue()
        ->and($signal['latency_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($signal['error'])->toBeNull();
});

it('leaves no probe behind in the cache', function () {
    app(CacheSignal::class)->collect();

    expect(Cache::getStore()->all())->toBeEmpty();
});

it('reports an unreachable cache as a fact', function () {
    $this->mock(Repository::class)->shouldReceive('put')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    $signal = app(CacheSignal::class)->collect();

    expect($signal)->toBe([
        'reachable' => false,
        'latency_ms' => null,
        'error' => 'Connection refused [tcp://127.0.0.1:6379]',
    ]);
});

it('reports a cache that loses what it was given as unreachable', function () {
    $cache = $this->mock(Repository::class);
    $cache->shouldReceive('put');
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('forget');

    $signal = app(CacheSignal::class)->collect();

    expect($signal['reachable'])->toBeFalse()
        ->and($signal['error'])->toBe('The cache did not return the value it was given.');
});

it('probes the cache even while the tick holds its overlap lock', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $ping = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, 'pingpong:ping'));

    expect($ping->mutex->create($ping))->toBeTrue();

    expect(app(CacheSignal::class)->collect()['reachable'])->toBeTrue();
});
