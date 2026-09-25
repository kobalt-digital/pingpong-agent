<?php

namespace KobaltDigital\PingPong\Scheduling;

use Closure;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use KobaltDigital\PingPong\Server;
use KobaltDigital\PingPong\Transport;
use Throwable;
use WeakMap;

/**
 * Sends Check-ins from the scheduler process itself, never from the queue,
 * so a Check-in arrives even when the workers are down.
 */
class ReportScheduledTasks
{
    public const MESSAGE_MAX_LENGTH = 255;

    public const BACKGROUND_RUN_ID_SECONDS = 86400;

    /** @var WeakMap<Event, string> */
    private WeakMap $runIds;

    public function __construct(
        private Transport $transport,
        private ScheduledTasks $tasks,
        private Repository $cache,
    ) {
        $this->runIds = new WeakMap;
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            ScheduledTaskStarting::class => 'starting',
            ScheduledTaskFinished::class => 'finished',
            ScheduledTaskFailed::class => 'failed',
            ScheduledTaskSkipped::class => 'skipped',
            ScheduledBackgroundTaskFinished::class => 'backgroundFinished',
        ];
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->safely(function () use ($event) {
            $runId = Str::uuid()->toString();

            $this->runIds[$event->task] = $runId;

            if ($event->task->runInBackground) {
                $this->cache->put($this->backgroundRunIdKey($event->task), $runId, self::BACKGROUND_RUN_ID_SECONDS);
            }

            $this->checkIn($event->task, CheckInSignal::START);
        });
    }

    /**
     * A command that exits non zero is followed by ScheduledTaskFailed, which
     * reports it. A background task has only been started when this fires;
     * `schedule:finish` reports it later. Any other task without an exit code
     * did not run: it found itself overlapping after it started.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        if ($event->task->runInBackground) {
            return;
        }

        $this->safely(function () use ($event) {
            if ($event->task->exitCode === null) {
                $this->checkIn($event->task, CheckInSignal::SKIPPED);

                return;
            }

            if ($event->task->exitCode !== 0) {
                return;
            }

            $this->checkIn($event->task, CheckInSignal::SUCCESS);
        });
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->safely(fn () => $this->checkIn($event->task, CheckInSignal::FAIL, $event->exception->getMessage()));
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->safely(fn () => $this->checkIn($event->task, CheckInSignal::SKIPPED));
    }

    /**
     * Fires in the `schedule:finish` process that follows a background task,
     * so the run id of the start comes from the cache.
     */
    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->safely(function () use ($event) {
            $runId = $this->cache->pull($this->backgroundRunIdKey($event->task));

            if (is_string($runId)) {
                $this->runIds[$event->task] = $runId;
            }

            $event->task->exitCode === 0
                ? $this->checkIn($event->task, CheckInSignal::SUCCESS)
                : $this->checkIn($event->task, CheckInSignal::FAIL);
        });
    }

    /**
     * Runs inside the host app's scheduler, so nothing may escape from here.
     */
    private function safely(Closure $report): void
    {
        if (! $this->transport->shouldSend()) {
            return;
        }

        try {
            $report();
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not send a Check-in.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function checkIn(Event $event, CheckInSignal $signal, ?string $message = null): void
    {
        $task = $this->tasks->find($event);

        if ($task === null) {
            $this->logUnnamedClosure($event, $signal);

            return;
        }

        $this->transport->send('api/agent/check-in', [
            'slug' => $task->slug,
            'signal' => $signal->value,
            'run_id' => $this->runIds[$event] ?? Str::uuid()->toString(),
            'cron' => $task->cron,
            'timezone' => $task->timezone,
            'overrides' => [
                'max_runtime' => $task->maxRuntime,
                'grace' => $task->grace,
            ],
            'exit_code' => $signal === CheckInSignal::FAIL ? $event->exitCode : null,
            'message' => $message === null ? null : Str::limit($message, self::MESSAGE_MAX_LENGTH - 3),
        ]);
    }

    private function backgroundRunIdKey(Event $event): string
    {
        return "pingpong-agent:run-id:{$event->mutexName()}:".Server::name();
    }

    /**
     * Once per run, at its start.
     */
    private function logUnnamedClosure(Event $event, CheckInSignal $signal): void
    {
        if ($signal !== CheckInSignal::START) {
            return;
        }

        if (! $this->tasks->isUnnamedClosure($event)) {
            return;
        }

        Log::info('PingPong Agent skipped a scheduled closure without a name. Give it one with ->name() to follow it in PingPong.');
    }
}
