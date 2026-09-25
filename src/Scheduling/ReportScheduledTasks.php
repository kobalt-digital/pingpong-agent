<?php

namespace KobaltDigital\PingPong\Scheduling;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /** @var WeakMap<Event, string> */
    private WeakMap $runIds;

    public function __construct(
        private Transport $transport,
        private ScheduledTasks $tasks,
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
        ];
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $this->runIds[$event->task] = Str::uuid()->toString();

        $this->checkIn($event->task, CheckInSignal::START);
    }

    /**
     * A command that exits non zero is followed by ScheduledTaskFailed, which
     * reports it. An exit code of null means the task did not run: it found
     * itself overlapping after it started.
     */
    public function finished(ScheduledTaskFinished $event): void
    {
        if ($event->task->exitCode === null) {
            $this->checkIn($event->task, CheckInSignal::SKIPPED);

            return;
        }

        if ($event->task->exitCode !== 0) {
            return;
        }

        $this->checkIn($event->task, CheckInSignal::SUCCESS);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->checkIn($event->task, CheckInSignal::FAIL, $event->exception->getMessage());
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->checkIn($event->task, CheckInSignal::SKIPPED);
    }

    /**
     * Runs inside the host app's scheduler, so nothing may escape from here.
     */
    private function checkIn(Event $event, CheckInSignal $signal, ?string $message = null): void
    {
        if (! $this->transport->shouldSend()) {
            return;
        }

        try {
            $this->send($event, $signal, $message);
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not send a Check-in.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function send(Event $event, CheckInSignal $signal, ?string $message): void
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
            'exit_code' => $signal === CheckInSignal::FAIL ? $event->exitCode : null,
            'message' => $message === null ? null : Str::limit($message, self::MESSAGE_MAX_LENGTH - 3),
        ]);
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
