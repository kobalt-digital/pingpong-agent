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
 * The updates by name come from `apt list --upgradable`, which takes a
 * moment, so it runs only while updates are pending and is remembered until
 * update-notifier counts again (apt does so whenever its lists or the
 * installed packages change), for a day at most.
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

        $state = [
            'uptime_seconds' => $this->host->uptime(),
            'reboot_required' => $this->host->rebootRequired(),
            'reboot_packages' => $this->host->rebootPackages(),
            'updates_pending' => $updates['pending'] ?? null,
            'updates_security' => $updates['security'] ?? null,
            'update_packages' => ($updates['pending'] ?? 0) > 0 ? $this->updatePackages() : null,
        ];

        if (array_filter($state, fn (mixed $value): bool => $value !== null) === []) {
            return null;
        }

        return $state;
    }

    /** @return list<array{name: string, version: ?string, security: bool}>|null */
    private function updatePackages(): ?array
    {
        $stamp = $this->host->updatesStamp();

        if ($this->cache === null || $stamp === null) {
            return $this->host->upgradablePackages();
        }

        $key = "pingpong-agent:update-packages:{$stamp}";

        try {
            $remembered = $this->cache->get($key);
        } catch (Throwable) {
            return $this->host->upgradablePackages();
        }

        if (is_array($remembered)) {
            /** @var list<array{name: string, version: ?string, security: bool}> $remembered */
            return $remembered;
        }

        $packages = $this->host->upgradablePackages();

        if ($packages !== null) {
            try {
                $this->cache->put($key, $packages, self::UPDATE_PACKAGES_TTL_SECONDS);
            } catch (Throwable) {
                return $packages;
            }
        }

        return $packages;
    }
}
