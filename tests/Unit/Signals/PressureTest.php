<?php

use KobaltDigital\PingPong\Signals\Pressure;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports the pressure stall information of the host', function () {
    $pressure = ['cpu_some' => 12.5, 'memory_some' => 0.0, 'memory_full' => 0.0, 'io_some' => 3.2, 'io_full' => 1.1];

    $signal = new Pressure(new FakeHost(pressure: $pressure));

    expect($signal->name())->toBe('pressure')
        ->and($signal->collect())->toBe($pressure);
});

it('gives nothing when the host has no /proc/pressure', function () {
    expect((new Pressure(new FakeHost(pressure: null)))->collect())->toBeNull();
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(Pressure::class);
});
