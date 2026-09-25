<?php

namespace KobaltDigital\PingPong\Scheduling;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository;
use KobaltDigital\PingPong\Server;
use Throwable;

/**
 * The tasks in the app's schedule as PingPong knows them. The full list only
 * travels when its hash differs from the last one PingPong received from this
 * server, so PingPong can archive a task that was removed from the code.
 */
class ScheduleManifest
{
    public function __construct(
        private Schedule $schedule,
        private ScheduledTasks $tasks,
        private Repository $cache,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return collect($this->schedule->events())
            ->map(fn ($event) => $this->tasks->find($event))
            ->filter()
            ->unique(fn (ScheduledTask $task) => $task->slug)
            ->sortBy(fn (ScheduledTask $task) => $task->slug)
            ->map(fn (ScheduledTask $task) => $task->toManifestEntry())
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function hash(array $entries): string
    {
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }

    /**
     * When the cache cannot tell, the list is sent: once too often is harmless.
     */
    public function isNew(string $hash): bool
    {
        try {
            return $this->cache->get($this->hashKey()) !== $hash;
        } catch (Throwable) {
            return true;
        }
    }

    public function delivered(string $hash): void
    {
        try {
            $this->cache->forever($this->hashKey(), $hash);
        } catch (Throwable) {
            return;
        }
    }

    private function hashKey(): string
    {
        return 'pingpong-agent:schedule-hash:'.Server::name();
    }
}
