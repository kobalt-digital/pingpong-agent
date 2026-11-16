<?php

use Illuminate\Contracts\Cache\Repository;
use KobaltDigital\PingPong\Signals\HostState;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports the uptime, the reboot and the updates of the host', function () {
    $signal = new HostState(new FakeHost(
        uptime: 3542791,
        rebootRequired: true,
        rebootPackages: ['linux-image-6.8.0-49-generic', 'libc6'],
        updates: ['pending' => 14, 'security' => 6],
    ));

    expect($signal->name())->toBe('host')
        ->and($signal->collect())->toBe([
            'uptime_seconds' => 3542791,
            'reboot_required' => true,
            'reboot_packages' => ['linux-image-6.8.0-49-generic', 'libc6'],
            'updates_pending' => 14,
            'updates_security' => 6,
            'update_packages' => [
                ['name' => 'openssl', 'version' => '3.0.13-0ubuntu3.5', 'security' => true],
                ['name' => 'nginx', 'version' => '1.24.0-2ubuntu7.2', 'security' => false],
            ],
        ]);
});

it('lists the updates by name only while some are pending, and asks apt once until update-notifier counts again', function () {
    $host = new class(updates: ['pending' => 2, 'security' => 1]) extends FakeHost
    {
        public int $asked = 0;

        public function upgradablePackages(): ?array
        {
            $this->asked++;

            return parent::upgradablePackages();
        }
    };
    $signal = new HostState($host, app(Repository::class));

    $signal->collect();
    $signal->collect();

    expect($host->asked)->toBe(1)
        ->and((new HostState(new FakeHost(updates: ['pending' => 0, 'security' => 0])))->collect()['update_packages'])->toBeNull();
});

it('sends null for what the host cannot tell', function () {
    $signal = new HostState(new FakeHost(uptime: 120, rebootRequired: null, rebootPackages: null, updates: null));

    expect($signal->collect())->toBe([
        'uptime_seconds' => 120,
        'reboot_required' => null,
        'reboot_packages' => null,
        'updates_pending' => null,
        'updates_security' => null,
        'update_packages' => null,
    ]);
});

it('gives nothing when the host can tell none of it', function () {
    $signal = new HostState(new FakeHost(uptime: null, rebootRequired: null, rebootPackages: null, updates: null));

    expect($signal->collect())->toBeNull();
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(HostState::class);
});
