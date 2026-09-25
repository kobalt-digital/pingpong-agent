<?php

namespace KobaltDigital\PingPong\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Date;
use KobaltDigital\PingPong\Transport;

/**
 * The one report that goes through the queue, because the queue is what it
 * tests. PingPong pairs it with the tick that carried the same canary id.
 */
class ReportQueueCanary implements ShouldQueue
{
    use Queueable;

    public const TIME_FORMAT = 'Y-m-d\TH:i:s.vp';

    /**
     * A retry would report a second, later run of the same canary.
     */
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $canaryId,
        public readonly string $dispatchedAt,
        public readonly string $dispatchedFrom,
    ) {}

    public function handle(Transport $transport): void
    {
        $transport->send('api/agent/canary', [
            'canary_id' => $this->canaryId,
            'dispatched_at' => $this->dispatchedAt,
            'dispatched_from' => $this->dispatchedFrom,
            'ran_at' => Date::now()->utc()->format(self::TIME_FORMAT),
        ]);
    }
}
