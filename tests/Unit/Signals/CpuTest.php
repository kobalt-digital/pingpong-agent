<?php

use KobaltDigital\PingPong\Signals\Cpu;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports the cpu time of the host since boot', function () {
    $times = ['user' => 123, 'nice' => 0, 'system' => 45, 'idle' => 9000, 'iowait' => 12, 'irq' => 0, 'softirq' => 3, 'steal' => 7];

    $cpu = new Cpu(new FakeHost(cpuTimes: $times));

    expect($cpu->name())->toBe('cpu')
        ->and($cpu->collect())->toBe($times);
});

it('gives nothing when the host has no /proc/stat', function () {
    expect((new Cpu(new FakeHost(cpuTimes: null)))->collect())->toBeNull();
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(Cpu::class);
});
