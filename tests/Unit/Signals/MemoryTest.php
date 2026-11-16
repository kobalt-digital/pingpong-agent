<?php

use KobaltDigital\PingPong\Signals\Memory;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports memory of the host in bytes, with the counters since boot', function () {
    $memory = new Memory(new FakeHost(
        memInfo: ['total_bytes' => 100, 'available_bytes' => 40, 'swap_used_bytes' => 5],
        vmStat: ['oom_kills' => 2, 'swap_in_pages' => 1843, 'swap_out_pages' => 5120],
    ));

    expect($memory->name())->toBe('memory')
        ->and($memory->collect())->toBe([
            'total_bytes' => 100,
            'available_bytes' => 40,
            'swap_used_bytes' => 5,
            'oom_kills' => 2,
            'swap_in_pages' => 1843,
            'swap_out_pages' => 5120,
        ]);
});

it('reports the counters as unknown when the host has no vmstat', function () {
    $memory = new Memory(new FakeHost(
        memInfo: ['total_bytes' => 100, 'available_bytes' => 40, 'swap_used_bytes' => 5],
        vmStat: null,
    ));

    expect($memory->collect())->toMatchArray(['oom_kills' => null, 'swap_in_pages' => null, 'swap_out_pages' => null]);
});

it('gives nothing when the host has no meminfo', function () {
    expect((new Memory(new FakeHost(memInfo: null)))->collect())->toBeNull();
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(Memory::class);
});
