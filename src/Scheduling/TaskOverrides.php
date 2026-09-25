<?php

namespace KobaltDigital\PingPong\Scheduling;

use Illuminate\Console\Scheduling\Event;
use WeakMap;

/**
 * Holds what `->pingpong()` set on a scheduled task, next to the task
 * rather than on it, since Event allows no properties of its own.
 */
class TaskOverrides
{
    /** @var WeakMap<Event, array{max_runtime: ?int, grace: ?int}> */
    private WeakMap $overrides;

    public function __construct()
    {
        $this->overrides = new WeakMap;
    }

    public function set(Event $event, ?int $maxRuntime, ?int $grace): void
    {
        $this->overrides[$event] = [
            'max_runtime' => $maxRuntime,
            'grace' => $grace,
        ];
    }

    /**
     * @return array{
     *     max_runtime: ?int,
     *     grace: ?int
     * }
     */
    public function for(Event $event): array
    {
        return $this->overrides[$event] ?? [
            'max_runtime' => null,
            'grace' => null,
        ];
    }
}
