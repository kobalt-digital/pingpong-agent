<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Throwable;

class FailedJobsSignal
{
    use CollectsFacts;

    public const DATABASE_DRIVERS = ['database', 'database-uuids'];

    public function __construct(
        private DatabaseManager $database,
        private Repository $cache,
    ) {}

    /**
     * @return array{
     *     new: ?int,
     *     error: ?string
     * }
     */
    public function collect(): array
    {
        $driver = config('queue.failed.driver');

        if (! in_array($driver, self::DATABASE_DRIVERS, true)) {
            return $this->unknown("Failed jobs are not stored in the database (driver: {$driver}).");
        }

        try {
            return $this->countSinceLastTick(
                $this->database->connection(config('queue.failed.database')),
                config('queue.failed.table', 'failed_jobs'),
            );
        } catch (Throwable $exception) {
            return $this->unknown($exception->getMessage());
        }
    }

    /**
     * The highest id seen on the previous tick is the high water mark. The
     * first tick only sets it, so a backlog from before the install is not
     * reported as new. The mark is kept per server, so every server reports
     * the same new failures, even when the servers share one cache.
     *
     * @return array{
     *     new: int,
     *     error: null
     * }|array{
     *     new: null,
     *     error: string
     * }
     */
    private function countSinceLastTick(Connection $connection, string $table): array
    {
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return $this->unknown("The {$table} table does not exist.");
        }

        $server = gethostname() ?: 'unknown';

        $markKey = "pingpong-agent:failed-jobs-mark:{$server}";

        $mark = $this->cache->get($markKey);

        $highestId = (int) $connection->table($table)->max('id');

        $this->cache->forever($markKey, $highestId);

        if ($mark === null) {
            return ['new' => 0, 'error' => null];
        }

        $tableWasEmptied = $highestId < $mark;

        $new = $connection->table($table)
            ->where('id', '>', $tableWasEmptied ? 0 : $mark)
            ->where('id', '<=', $highestId)
            ->count();

        return ['new' => $new, 'error' => null];
    }

    /**
     * @return array{
     *     new: null,
     *     error: string
     * }
     */
    private function unknown(string $message): array
    {
        return [
            'new' => null,
            'error' => $this->error($message),
        ];
    }
}
