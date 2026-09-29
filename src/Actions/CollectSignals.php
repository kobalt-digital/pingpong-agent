<?php

namespace KobaltDigital\PingPong\Actions;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Contracts\Collector;
use Throwable;

class CollectSignals
{
    public function __construct(private Application $app) {}

    /** @return array<string, mixed> */
    public function execute(): array
    {
        $signals = [];

        foreach (config('pingpong-agent.signals', []) as $collectorClass) {
            $collected = $this->collect($collectorClass);

            if ($collected === null) {
                continue;
            }

            [$name, $signal] = $collected;

            $signals[$name] = $signal;
        }

        return $signals;
    }

    /**
     * A collector that throws costs one warning, never the tick.
     *
     * @param  class-string<Collector>  $collectorClass
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function collect(string $collectorClass): ?array
    {
        try {
            $collector = $this->app->make($collectorClass);

            $signal = $collector->collect();
            $name = $collector->name();
        } catch (Throwable $exception) {
            Log::warning("PingPong Agent could not collect a signal with {$collectorClass}.", [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($signal === null) {
            return null;
        }

        return [$name, $signal];
    }
}
