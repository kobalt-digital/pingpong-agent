<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use KobaltDigital\PingPong\Signals\Cache;

it('reports the cache as reachable with its latency', function () {
    $signal = app(Cache::class)->collect();

    expect($signal['reachable'])->toBeTrue()
        ->and($signal['latency_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($signal['error'])->toBeNull();
});

it('leaves no probe behind in the cache', function () {
    app(Cache::class)->collect();

    expect(app('cache.store')->getStore()->all())->toBeEmpty();
});

it('reports an unreachable cache as a fact', function () {
    $this->mock(Repository::class)->shouldReceive('put')->andThrow(new RuntimeException('Connection refused [tcp://127.0.0.1:6379]'));

    $signal = app(Cache::class)->collect();

    expect($signal)->toBe([
        'reachable' => false,
        'latency_ms' => null,
        'error' => 'Connection refused [tcp://127.0.0.1:6379]',
        'driver' => 'array',
    ]);
});

it('reports a cache that loses what it was given as unreachable', function () {
    $cache = $this->mock(Repository::class);
    $cache->shouldReceive('put');
    $cache->shouldReceive('get')->andReturn(null);
    $cache->shouldReceive('forget');

    $signal = app(Cache::class)->collect();

    expect($signal['reachable'])->toBeFalse()
        ->and($signal['error'])->toBe('The cache did not return the value it was given.');
});

it('probes the cache even while the tick holds its overlap lock', function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');

    $ping = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command, 'pingpong:ping'));

    expect($ping->mutex->create($ping))->toBeTrue();

    expect(app(Cache::class)->collect()['reachable'])->toBeTrue();
});

it('names the driver of the default store, and sends no Redis facts for another driver', function () {
    $signal = app(Cache::class)->collect();

    expect($signal['driver'])->toBe('array')
        ->and($signal)->not->toHaveKey('redis');
});

it('reads the Redis INFO fields from phpredis and from Predis alike', function () {
    $facts = ['version' => '7.2.4', 'used_memory_bytes' => 412000000, 'maxmemory_bytes' => 1073741824, 'connected_clients' => 23, 'evicted_keys' => 4];

    expect(Cache::redisFacts(['redis_version' => '7.2.4', 'used_memory' => '412000000', 'maxmemory' => '1073741824', 'connected_clients' => '23', 'evicted_keys' => '4', 'role' => 'master']))->toBe($facts)
        ->and(Cache::redisFacts([
            'Server' => ['redis_version' => '7.2.4'],
            'Clients' => ['connected_clients' => 23],
            'Memory' => ['used_memory' => 412000000, 'maxmemory' => 1073741824],
            'Stats' => ['evicted_keys' => 4],
        ]))->toBe($facts)
        ->and(Cache::redisFacts(['role' => 'master']))->toBeNull();
});

it('bounds INFO by a timeout of its own and gives the app its connection back as it was', function () {
    $client = Mockery::mock(Redis::class);
    $client->shouldReceive('getOption')->with(Redis::OPT_READ_TIMEOUT)->andReturn(0.0);
    $client->shouldReceive('setOption')->once()->ordered()->with(Redis::OPT_READ_TIMEOUT, Cache::INFO_TIMEOUT_SECONDS)->andReturnTrue();
    $client->shouldReceive('info')->once()->ordered()->andThrow(new RedisException('read error on connection'));
    $client->shouldReceive('setOption')->once()->ordered()->with(Redis::OPT_READ_TIMEOUT, 0.0)->andReturnTrue();

    expect(fn () => Cache::boundedInfo($client))->toThrow(RedisException::class);
})->skip(! extension_loaded('redis'), 'phpredis is not installed');

it('sends no Redis facts through a client it cannot bound', function () {
    expect(Cache::boundedInfo(new stdClass))->toBeNull();
});
