<?php

namespace KobaltDigital\PingPong\Contracts;

interface Collector
{
    public function name(): string;

    /**
     * The signal's value, or null when this host cannot provide it. May
     * throw; CollectSignals turns that into a logged warning.
     *
     * @return array<string, mixed>|null
     */
    public function collect(): ?array;
}
