<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use Throwable;

class CacheSignal
{
    use CollectsFacts;

    public const PROBE_SECONDS = 60;

    public function __construct(private Repository $cache) {}

    /**
     * Writes, reads and removes a key of its own. The overlap lock of the
     * tick lives in the same store, but holding a lock proves nothing about
     * whether the store still reads and writes.
     *
     * @return array{
     *     reachable: bool,
     *     latency_ms: ?float,
     *     error: ?string
     * }
     */
    public function collect(): array
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
