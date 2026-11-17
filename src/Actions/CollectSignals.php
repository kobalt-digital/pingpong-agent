<?php

namespace KobaltDigital\PingPong\Actions;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Contracts\MachineSignal;
use KobaltDigital\PingPong\Host;
use Throwable;
use UnexpectedValueException;

class CollectSignals
{
    public function __construct(private Application $app, private Host $host) {}

    /** @return array<string, mixed> */
    public function execute(): array
    {
        $signals = [];

        try {
            $collectors = config()->array('pingpong-agent.signals', []);
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not read the signal configuration.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }

        foreach ($collectors as $collectorClass) {
            if (! is_string($collectorClass) || ! is_a($collectorClass, Collector::class, true)) {
                Log::warning('PingPong Agent ignores a signal that does not implement Collector.');

                continue;
            }

            /* The machine agent reads the machine, once for every app on it;
               only an ECS task, which cannot run it, still reads its own. */
            if (is_a($collectorClass, MachineSignal::class, true) && ! $this->host->inFleet()) {
                continue;
            }

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

            if (! $collector instanceof Collector) {
                throw new UnexpectedValueException('The resolved signal must implement Collector.');
            }

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
