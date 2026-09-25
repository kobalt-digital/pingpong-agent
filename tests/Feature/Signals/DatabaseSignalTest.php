<?php

use Illuminate\Support\Facades\DB;
use KobaltDigital\PingPong\Signals\DatabaseSignal;

it('reports the database as reachable with its latency', function () {
    $signal = app(DatabaseSignal::class)->collect();

    expect($signal['reachable'])->toBeTrue()
        ->and($signal['latency_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($signal['error'])->toBeNull();
});

it('reports an unreachable database as a fact', function () {
    config()->set('database.connections.testing.database', '/nonexistent/pingpong.sqlite');

    DB::purge();

    $signal = app(DatabaseSignal::class)->collect();

    expect($signal['reachable'])->toBeFalse()
        ->and($signal['latency_ms'])->toBeNull()
        ->and($signal['error'])->toContain('pingpong.sqlite');
});

it('keeps the error short enough for PingPong to store', function () {
    config()->set('database.connections.testing.database', '/nonexistent/'.str_repeat('a', 300).'.sqlite');

    DB::purge();

    $signal = app(DatabaseSignal::class)->collect();

    expect($signal['reachable'])->toBeFalse()
        ->and(mb_strlen($signal['error']))->toBeLessThanOrEqual(255);
});
