<?php

namespace KobaltDigital\PingPong\Signals;

use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\Host;

/**
 * How long the machine has been up, whether it waits for a reboot and how
 * many package updates wait, so PingPong can tell a server that needs care.
 */
class HostState implements Collector
{
    public function __construct(private Host $host) {}

    public function name(): string
    {
        return 'host';
    }

    /** @return array{uptime_seconds: ?int, reboot_required: ?bool, reboot_packages: ?list<string>, updates_pending: ?int, updates_security: ?int}|null */
    public function collect(): ?array
    {
        $updates = $this->host->updates();

        $state = [
            'uptime_seconds' => $this->host->uptime(),
            'reboot_required' => $this->host->rebootRequired(),
            'reboot_packages' => $this->host->rebootPackages(),
            'updates_pending' => $updates['pending'] ?? null,
            'updates_security' => $updates['security'] ?? null,
        ];

        if (array_filter($state, fn (mixed $value): bool => $value !== null) === []) {
            return null;
        }

        return $state;
    }
}
