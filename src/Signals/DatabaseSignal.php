<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Database\DatabaseManager;
use Throwable;

class DatabaseSignal
{
    use CollectsFacts;

    public function __construct(private DatabaseManager $database) {}

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
