<?php

namespace KobaltDigital\PingPong\Signals;

use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\DatabaseProbe;
use Throwable;

class Database implements Collector
{
    use CollectsFacts;

    public function __construct(private DatabaseProbe $probe) {}

    public function name(): string
    {
        return 'database';
    }

    /**
     * Only the query is timed; the probe has already connected.
     *
     * @return array{
     *     reachable: bool,
     *     latency_ms: ?float,
     *     error: ?string
     * }
     */
    public function collect(): array
    {
        $connection = $this->probe->connection();

        if ($connection === null) {
            return $this->unreachable((string) $this->probe->error());
        }

        $startedAt = hrtime(true);

        try {
            // The write connection is the one the probe opened; a read
            // replica would put a second connect inside the timer.
            $connection->select('select 1', useReadPdo: false);
        } catch (Throwable $exception) {
            return $this->unreachable($exception->getMessage());
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
