<?php

namespace KobaltDigital\PingPong\Actions;

use KobaltDigital\PingPong\DeliveredHashes;
use KobaltDigital\PingPong\Inventory;
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
        private Inventory $inventory,
        private DeliveredHashes $deliveredHashes,
    ) {}

    public function execute(): bool
    {
        $tasks = $this->schedule->entries();

        $scheduleHash = $this->schedule->hash($tasks);

        $scheduleIsNew = $this->deliveredHashes->isNew('schedule', $scheduleHash);

        $inventoryHash = $this->inventory->hash();

        $inventoryIsNew = $this->deliveredHashes->isNew('inventory', $inventoryHash);

        $payload = [
            'schedule_hash' => $scheduleHash,
            'inventory_hash' => $inventoryHash,
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

        if ($inventoryIsNew) {
            $payload['inventory'] = $this->inventory->collect();
        }

        $delivered = $this->transport->send('api/agent/tick', $payload);

        if (! $delivered) {
            return false;
        }

        if ($scheduleIsNew) {
            $this->deliveredHashes->remember('schedule', $scheduleHash);
        }

        if ($inventoryIsNew) {
            $this->deliveredHashes->remember('inventory', $inventoryHash);
        }

        return true;
    }
}
