<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Database\DatabaseManager;
use KobaltDigital\PingPong\Contracts\Collector;
use Throwable;

class Database implements Collector
{
    use CollectsFacts;

    public function __construct(private DatabaseManager $database) {}

    public function name(): string
    {
        return 'database';
    }

    /**
     * @return array{
     *     reachable: bool,
     *     latency_ms: ?float,
     *     error: ?string
     * }
     */
    public function collect(): array
    {
        $startedAt = hrtime(true);

        try {
            $this->database->connection()->select('select 1');
        } catch (Throwable $exception) {
            return [
                'reachable' => false,
                'latency_ms' => null,
                'error' => $this->error($exception->getMessage()),
            ];
        }

        return [
            'reachable' => true,
            'latency_ms' => $this->millisecondsSince($startedAt),
            'error' => null,
        ];
    }
}
