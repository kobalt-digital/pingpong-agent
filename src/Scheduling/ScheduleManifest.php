<?php

namespace KobaltDigital\PingPong\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

/**
 * The tasks in the app's schedule as PingPong knows them. PingPong archives
 * a task that is missing from the list.
 */
class ScheduleManifest
{
    public function __construct(
        private Schedule $schedule,
        private ScheduledTasks $tasks,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return array_values(collect($this->schedule->events())
            ->map(fn ($event) => $this->tasks->find($event))
            ->filter()
            ->unique(fn (ScheduledTask $task) => $task->slug)
            ->sortBy(fn (ScheduledTask $task) => $task->slug)
            ->map(fn (ScheduledTask $task) => $task->toManifestEntry())
            ->all());
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    public function hash(array $entries): string
    {
        return hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
