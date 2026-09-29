<?php

use KobaltDigital\PingPong\Signals\Memory;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports memory of the host in bytes', function () {
    $memory = new Memory(new FakeHost(memInfo: ['total_bytes' => 100, 'available_bytes' => 40, 'swap_used_bytes' => 5]));

    expect($memory->name())->toBe('memory')
        ->and($memory->collect())->toBe(['total_bytes' => 100, 'available_bytes' => 40, 'swap_used_bytes' => 5]);
});

it('gives nothing when the host has no meminfo', function () {
    expect((new Memory(new FakeHost(memInfo: null)))->collect())->toBeNull();
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(Memory::class);
});
