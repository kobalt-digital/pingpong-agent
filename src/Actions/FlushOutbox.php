<?php

namespace KobaltDigital\PingPong\Actions;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Delivery\Outbox;
use KobaltDigital\PingPong\Delivery\Outcome;
use KobaltDigital\PingPong\Scheduling\ReportScheduledTasks;
use KobaltDigital\PingPong\Transport;

/**
 * Replays kept Check-ins oldest first, unchanged, so PingPong files each
 * under the run id and time it happened. Bounded per tick, since the tick
 * runs in the scheduler and the rest waits for the next one.
 */
class FlushOutbox
{
    public const MAX_ENTRIES_PER_TICK = 50;

    public const MAX_SECONDS_PER_TICK = 10;

    public function __construct(
        private Transport $transport,
        private Outbox $outbox,
    ) {}

    public function execute(): int
    {
        $deadline = Date::now()->addSeconds(self::MAX_SECONDS_PER_TICK);

        $replayed = 0;

        foreach ($this->outbox->oldest(self::MAX_ENTRIES_PER_TICK) as $entry) {
            if (Date::now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            $outcome = $this->transport->attempt(ReportScheduledTasks::CHECK_IN_PATH, $entry['payload'])->outcome;

            if ($outcome === Outcome::UNDELIVERED || $outcome === Outcome::SKIPPED) {
                break;
            }

            $this->outbox->remove($entry['id']);

            if ($outcome === Outcome::DELIVERED) {
                $replayed++;
            }
        }

        if ($replayed > 0) {
            Log::info("PingPong Agent replayed {$replayed} Check-ins it kept while PingPong was unreachable.");
        }

        return $replayed;
    }
}
