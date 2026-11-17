<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Cache\Repository;
use KobaltDigital\PingPong\Contracts\MachineSignal;
use KobaltDigital\PingPong\Host;
use Throwable;

/**
 * How long the machine has been up, whether it waits for a reboot and which
 * package updates wait, so PingPong can tell a server that needs care.
 *
 * Ubuntu's update-notifier counts the updates. Where it does not run
 * (Debian, a minimal Ubuntu) apt counts them. The updates by name come from
 * `apt list --upgradable`, which takes a moment, so it runs only while
 * updates are pending, or to count them, and is remembered until the updates
 * change (`Host::updatesStamp()`: apt's lists or the installed packages), for
 * a day at most.
 */
class HostState implements MachineSignal
{
    public const UPDATE_PACKAGES_TTL_SECONDS = 86400;

    public function __construct(private Host $host, private ?Repository $cache = null) {}

    public function name(): string
    {
        return 'host';
    }

    /** @return array{uptime_seconds: ?int, reboot_required: ?bool, reboot_packages: ?list<string>, updates_pending: ?int, updates_security: ?int, update_packages: ?list<array{name: string, version: ?string, security: bool}>}|null */
    public function collect(): ?array
    {
        $updates = $this->host->updates();
        $upgradable = $updates === null || $updates['pending'] > 0 ? $this->upgradable() : null;
        $updates ??= $upgradable === null ? null : ['pending' => $upgradable['pending'], 'security' => $upgradable['security']];

        $state = [
            'uptime_seconds' => $this->host->uptime(),
            'reboot_required' => $this->host->rebootRequired(),
            'reboot_packages' => $this->host->rebootPackages(),
            'updates_pending' => $updates['pending'] ?? null,
            'updates_security' => $updates['security'] ?? null,
            'update_packages' => ($updates['pending'] ?? 0) > 0 ? ($upgradable['packages'] ?? null) : null,
        ];

        if (array_filter($state, fn (mixed $value): bool => $value !== null) === []) {
            return null;
        }

        return $state;
    }

    /** @return array{pending: int, security: int, packages: list<array{name: string, version: ?string, security: bool}>}|null */
    private function upgradable(): ?array
    {
        $stamp = $this->host->updatesStamp();

        if ($this->cache === null || $stamp === null) {
            return $this->host->upgradable();
        }

        $key = "pingpong-agent:upgradable:{$stamp}";

        try {
            $remembered = $this->cache->get($key);
        } catch (Throwable) {
            return $this->host->upgradable();
        }

        if (is_array($remembered) && isset($remembered['pending'], $remembered['security'], $remembered['packages'])) {
            /** @var array{pending: int, security: int, packages: list<array{name: string, version: ?string, security: bool}>} $remembered */
            return $remembered;
        }

        $upgradable = $this->host->upgradable();

        if ($upgradable !== null) {
            try {
                $this->cache->put($key, $upgradable, self::UPDATE_PACKAGES_TTL_SECONDS);
            } catch (Throwable) {
                return $upgradable;
            }
        }

        return $upgradable;
    }
}
