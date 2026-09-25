<?php

namespace KobaltDigital\PingPong\Actions;

use Illuminate\Http\Client\Response;
use KobaltDigital\PingPong\DeliveredHashes;
use KobaltDigital\PingPong\Inventory;
use KobaltDigital\PingPong\Scheduling\ScheduleManifest;
use KobaltDigital\PingPong\Signals\CacheSignal;
use KobaltDigital\PingPong\Signals\DatabaseSignal;
use KobaltDigital\PingPong\Signals\DiskSignal;
use KobaltDigital\PingPong\Signals\FailedJobsSignal;
use KobaltDigital\PingPong\Signals\QueueSignal;
use KobaltDigital\PingPong\Transport;
use Throwable;

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

        $response = $this->transport->deliver('api/agent/tick', $payload);

        if ($response === null) {
            return false;
        }

        if ($scheduleIsNew) {
            $this->deliveredHashes->remember('schedule', $scheduleHash);
        }

        if ($inventoryIsNew) {
            $this->deliveredHashes->remember('inventory', $inventoryHash);
        }

        if ($this->asksFor($response, 'send_tasks')) {
            $this->deliveredHashes->forget('schedule');
        }

        if ($this->asksFor($response, 'send_inventory')) {
            $this->deliveredHashes->forget('inventory');
        }

        return true;
    }

    /**
     * PingPong asks for a list again when it lacks the one this server marked
     * delivered, for instance because it ticked before PingPong read it. Only
     * a literal true asks; any other answer, JSON or not, is ignored.
     */
    private function asksFor(Response $response, string $flag): bool
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return false;
        }

        return is_array($body) && ($body[$flag] ?? null) === true;
    }
}
