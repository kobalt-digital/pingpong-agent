<?php

use KobaltDigital\PingPong\Signals\Load;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports the load average of the host', function () {
    $load = new Load(new FakeHost(loadAverage: ['1m' => 1.5, '5m' => 1.0, '15m' => 0.5]));

    expect($load->name())->toBe('load')
        ->and($load->collect())->toBe(['1m' => 1.5, '5m' => 1.0, '15m' => 0.5]);
});

it('gives nothing when the host has no load average', function () {
    expect((new Load(new FakeHost(loadAverage: null)))->collect())->toBeNull();
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(Load::class);
});
