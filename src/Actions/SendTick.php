<?php

namespace KobaltDigital\PingPong\Actions;

use KobaltDigital\PingPong\DeliveredHashes;
use KobaltDigital\PingPong\Scheduling\ScheduleManifest;
use KobaltDigital\PingPong\Signals\CacheSignal;
use KobaltDigital\PingPong\Signals\DatabaseSignal;
use KobaltDigital\PingPong\Signals\DiskSignal;
use KobaltDigital\PingPong\Signals\FailedJobsSignal;
use KobaltDigital\PingPong\Signals\QueueSignal;
use KobaltDigital\PingPong\Transport;

class SendTick
{
    public function __construct(
        private Transport $transport,
        private DatabaseSignal $database,
        private CacheSignal $cache,
        private DiskSignal $disk,
        private FailedJobsSignal $failedJobs,
        private QueueSignal $queue,
        private ScheduleManifest $schedule,
        private DeliveredHashes $deliveredHashes,
    ) {}

    public function execute(): bool
    {
        $tasks = $this->schedule->entries();

        $scheduleHash = $this->schedule->hash($tasks);

        $scheduleIsNew = $this->deliveredHashes->isNew('schedule', $scheduleHash);

        $payload = [
            'schedule_hash' => $scheduleHash,
            'signals' => [
                'database' => $this->database->collect(),
                'cache' => $this->cache->collect(),
                'disk' => $this->disk->collect(),
                'failed_jobs' => $this->failedJobs->collect(),
                'queue' => $this->queue->collect(),
            ],
        ];

        if ($scheduleIsNew) {
            $payload['tasks'] = $tasks;
        }

        $delivered = $this->transport->send('api/agent/tick', $payload);

        if ($delivered && $scheduleIsNew) {
            $this->deliveredHashes->remember('schedule', $scheduleHash);
        }

        return $delivered;
    }
}
