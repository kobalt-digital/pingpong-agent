<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use KobaltDigital\PingPong\Jobs\ReportQueueCanary;
use KobaltDigital\PingPong\Server;
use Throwable;

class QueueSignal
{
    use CollectsFacts;

    /**
     * These run a job inside the process that dispatched it, or not at all,
     * so a canary would prove nothing about the app's workers.
     */
    public const DRIVERS_WITHOUT_WORKERS = ['sync', 'deferred', 'background', 'null'];

    public function __construct(private Dispatcher $bus) {}

    /**
     * @return array{
     *     connection: ?string,
     *     driver: ?string,
     *     canary_id: ?string,
     *     error: ?string
     * }
     */
    public function collect(): array
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");

        if (in_array($driver, self::DRIVERS_WITHOUT_WORKERS, true)) {
            return $this->fact($connection, $driver);
        }

        $canaryId = Str::uuid()->toString();

        try {
            $this->bus->dispatch(new ReportQueueCanary(
                $canaryId,
                Date::now()->utc()->format(ReportQueueCanary::TIME_FORMAT),
                Server::name(),
            ));
        } catch (Throwable $exception) {
            return $this->fact($connection, $driver, error: $this->error($exception->getMessage()));
        }

        return $this->fact($connection, $driver, canaryId: $canaryId);
    }

    /**
     * @return array{
     *     connection: ?string,
     *     driver: ?string,
     *     canary_id: ?string,
     *     error: ?string
     * }
     */
    private function fact(?string $connection, ?string $driver, ?string $canaryId = null, ?string $error = null): array
    {
        return [
            'connection' => $connection,
            'driver' => $driver,
            'canary_id' => $canaryId,
            'error' => $error,
        ];
    }
}
