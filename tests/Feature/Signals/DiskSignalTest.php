<?php

use KobaltDigital\PingPong\Signals\DiskSignal;

it('reports the free and total bytes of the disk the app lives on', function () {
    $signal = app(DiskSignal::class)->collect();

    expect($signal['free_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($signal['total_bytes'])->toBeInt()->toBeGreaterThanOrEqual($signal['free_bytes'])
        ->and($signal['error'])->toBeNull();
});

it('reports an unreadable disk as a fact', function () {
    app()->setBasePath('/nonexistent/app');

    $signal = app(DiskSignal::class)->collect();

    expect($signal['free_bytes'])->toBeNull()
        ->and($signal['total_bytes'])->toBeNull()
        ->and($signal['error'])->toBeString()->not->toBeEmpty();
});
