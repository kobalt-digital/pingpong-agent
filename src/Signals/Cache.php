<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use KobaltDigital\PingPong\Contracts\Collector;
use Throwable;

class Cache implements Collector
{
    use CollectsFacts;

    public const PROBE_SECONDS = 60;

    /** How long Redis may take to answer INFO before the tick goes on without it. */
    public const INFO_TIMEOUT_SECONDS = 1;

    public function __construct(private Repository $cache) {}

    public function name(): string
    {
        return 'cache';
    }

    /**
     * Writes, reads and removes a key of its own. The overlap lock of the
     * tick lives in the same store, but holding a lock proves nothing about
     * whether the store still reads and writes. Names the driver of the
     * store, and on Redis adds what Redis says about itself (`redis`, left
     * out on any other driver and when INFO cannot be read).
     *
     * @return array{
     *     reachable: bool,
     *     latency_ms: ?float,
     *     error: ?string,
     *     driver: ?string,
     *     redis?: array{version: ?string, used_memory_bytes: ?int, maxmemory_bytes: ?int, connected_clients: ?int, evicted_keys: ?int}
     * }
     */
    public function collect(): array
    {
        $probe = $this->probe();
        $redis = $probe['reachable'] ? $this->redis() : null;

        return [...$probe, 'driver' => $this->driver(), ...($redis === null ? [] : ['redis' => $redis])];
    }

    /**
     * The fields of Redis INFO PingPong shows, from either client's answer:
     * phpredis gives one flat list, Predis one list per section.
     *
     * @param  array<array-key, mixed>  $info
     * @return array{version: ?string, used_memory_bytes: ?int, maxmemory_bytes: ?int, connected_clients: ?int, evicted_keys: ?int}|null
     */
    public static function redisFacts(array $info): ?array
    {
        $flat = [];

        foreach ($info as $key => $value) {
            if (is_array($value)) {
                $flat = [...$flat, ...$value];
            } else {
                $flat[$key] = $value;
            }
        }

        $integer = fn (string $key): ?int => is_numeric($flat[$key] ?? null) ? (int) $flat[$key] : null;

        $facts = [
            'version' => is_scalar($flat['redis_version'] ?? null) ? mb_substr((string) $flat['redis_version'], 0, 32) : null,
            'used_memory_bytes' => $integer('used_memory'),
            'maxmemory_bytes' => $integer('maxmemory'),
            'connected_clients' => $integer('connected_clients'),
            'evicted_keys' => $integer('evicted_keys'),
        ];

        return array_filter($facts, fn (mixed $value): bool => $value !== null) === [] ? null : $facts;
    }

    /** The driver of the app's default cache store, such as `redis` or `database`. */
    private function driver(): ?string
    {
        $store = config('cache.default');
        $driver = is_string($store) ? config("cache.stores.{$store}.driver") : null;

        return is_string($driver) ? mb_substr($driver, 0, 32) : null;
    }

    /** @return array{version: ?string, used_memory_bytes: ?int, maxmemory_bytes: ?int, connected_clients: ?int, evicted_keys: ?int}|null */
    private function redis(): ?array
    {
        $store = $this->cache instanceof \Illuminate\Cache\Repository ? $this->cache->getStore() : null;

        if (! $store instanceof RedisStore) {
            return null;
        }

        try {
            $info = self::boundedInfo($store->connection()->client());
        } catch (Throwable) {
            return null;
        }

        return is_array($info) ? self::redisFacts($info) : null;
    }

    /**
     * INFO with a read timeout of its own, so a Redis that stops answering
     * cannot hold up the tick: the connection's own timeout is put back
     * after, since the app shares it. Only phpredis lets one call be bounded
     * without touching the connection, so Predis sends no Redis facts.
     *
     * @return array<array-key, mixed>|null
     */
    public static function boundedInfo(mixed $client): ?array
    {
        if (! $client instanceof \Redis) {
            return null;
        }

        $previous = $client->getOption(\Redis::OPT_READ_TIMEOUT);
        $client->setOption(\Redis::OPT_READ_TIMEOUT, self::INFO_TIMEOUT_SECONDS);

        try {
            $info = $client->info();
        } finally {
            $client->setOption(\Redis::OPT_READ_TIMEOUT, $previous);
        }

        return is_array($info) ? $info : null;
    }

    /**
     * @return array{
     *     reachable: bool,
     *     latency_ms: ?float,
     *     error: ?string
     * }
     */
    private function probe(): array
    {
        $probe = Str::random(16);

        $startedAt = hrtime(true);

        try {
            $this->cache->put("pingpong-agent:cache-probe:{$probe}", $probe, self::PROBE_SECONDS);

            $readBack = $this->cache->get("pingpong-agent:cache-probe:{$probe}");

            $this->cache->forget("pingpong-agent:cache-probe:{$probe}");
        } catch (Throwable $exception) {
            return $this->unreachable($exception->getMessage());
        }

        if ($readBack !== $probe) {
            return $this->unreachable('The cache did not return the value it was given.');
        }

        return [
            'reachable' => true,
            'latency_ms' => $this->millisecondsSince($startedAt),
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     reachable: false,
     *     latency_ms: null,
     *     error: string
     * }
     */
    private function unreachable(string $message): array
    {
        return [
            'reachable' => false,
            'latency_ms' => null,
            'error' => $this->error($message),
        ];
    }
}
