<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\DatabaseProbe;
use KobaltDigital\PingPong\Jobs\ReportQueueCanary;
use KobaltDigital\PingPong\Server;
use stdClass;
use Throwable;

class Queue implements Collector
{
    use CollectsFacts;

    /**
     * These run a job inside the process that dispatched it, or not at all,
     * so a canary would prove nothing about the app's workers.
     */
    public const DRIVERS_WITHOUT_WORKERS = ['sync', 'deferred', 'background', 'null'];

    /**
     * No index answers "unreserved jobs per queue", so the counts come from
     * at most this many of the oldest unreserved jobs. A total of this many
     * across all queues means "this many or more".
     */
    public const PENDING_CAP = 10000;

    public function __construct(
        private Dispatcher $bus,
        private DatabaseProbe $probe,
    ) {}

    public function name(): string
    {
        return 'queue';
    }

    /**
     * The backlog is read before the canary is dispatched, so this tick's
     * canary is not counted in it.
     *
     * @return array{
     *     connection: ?string,
     *     driver: ?string,
     *     canary_id: ?string,
     *     error: ?string,
     *     pending?: array<string, int>|stdClass,
     *     oldest_pending_seconds?: int
     * }
     */
    public function collect(): array
    {
        $connection = config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");

        if ($driver !== 'database') {
            return $this->canary($connection, $driver);
        }

        try {
            $database = $this->probe->connectionFor(config("queue.connections.{$connection}.connection"));
        } catch (Throwable $exception) {
            return $this->fact($connection, $driver, error: $this->error($exception->getMessage()));
        }

        // Dispatching would connect to the dead default database again,
        // without the probe's connect timeout.
        if ($database === null) {
            return $this->fact($connection, $driver, error: $this->error((string) $this->probe->error()));
        }

        $backlog = $this->backlog($database, config("queue.connections.{$connection}.table", 'jobs'));

        return [...$this->canary($connection, $driver), ...$backlog];
    }

    /**
     * @return array{
     *     connection: ?string,
     *     driver: ?string,
     *     canary_id: ?string,
     *     error: ?string
     * }
     */
    private function canary(?string $connection, ?string $driver): array
    {
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
     * Only the database driver keeps its backlog somewhere the Agent can
     * count cheaply. A failed count leaves the backlog keys out; the canary
     * still reports the queue.
     *
     * @return array{pending?: array<string, int>|stdClass, oldest_pending_seconds?: int}
     */
    private function backlog(Connection $database, string $table): array
    {
        try {
            return $this->countPending($database, $table);
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not read the queue backlog.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Reserved jobs are being worked on and are not backlog. Ids follow
     * dispatch order, so the capped set holds the oldest jobs.
     *
     * @return array{pending: array<string, int>|stdClass, oldest_pending_seconds?: int}
     */
    private function countPending(Connection $connection, string $table): array
    {
        $now = Date::now()->getTimestamp();

        $cappedJobs = $connection
            ->table($table)
            ->select(['queue', 'available_at'])
            ->whereNull('reserved_at')
            ->orderBy('id')
            ->limit(self::PENDING_CAP);

        $rows = $connection->query()
            ->useWritePdo()
            ->fromSub($cappedJobs, 'capped_jobs')
            ->selectRaw('queue, count(*) as pending, min(case when available_at <= ? then available_at end) as oldest_due_at', [$now])
            ->groupBy('queue')
            ->orderBy('queue')
            ->get();

        $pending = [];
        $oldestDueAt = null;

        foreach ($rows as $row) {
            $pending[$row->queue] = (int) $row->pending;

            if ($row->oldest_due_at === null) {
                continue;
            }

            $oldestDueAt = $oldestDueAt === null
                ? (int) $row->oldest_due_at
                : min($oldestDueAt, (int) $row->oldest_due_at);
        }

        $backlog = ['pending' => $pending === [] ? new stdClass : $pending];

        if ($oldestDueAt !== null) {
            $backlog['oldest_pending_seconds'] = $now - $oldestDueAt;
        }

        return $backlog;
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
