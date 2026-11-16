<?php

use KobaltDigital\PingPong\Host;
use KobaltDigital\PingPong\Signals\Disk;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

it('reports the free and total bytes of the disk the app lives on', function () {
    $signal = app(Disk::class)->collect();

    expect($signal['free_bytes'])->toBeInt()->toBeGreaterThan(0)
        ->and($signal['total_bytes'])->toBeInt()->toBeGreaterThanOrEqual($signal['free_bytes'])
        ->and($signal['error'])->toBeNull();
});

it('reports an unreadable disk as a fact', function () {
    app()->setBasePath('/nonexistent/app');

    $signal = app(Disk::class)->collect();

    expect($signal['free_bytes'])->toBeNull()
        ->and($signal['total_bytes'])->toBeNull()
        ->and($signal['inodes_total'])->toBeNull()
        ->and($signal['inodes_free'])->toBeNull()
        ->and($signal['error'])->toBeString()->not->toBeEmpty();
});

it('reports the inodes of the filesystem the app lives on', function () {
    app()->instance(Host::class, new FakeHost(inodes: ['total' => 6553600, 'free' => 6141255]));

    expect(app(Disk::class)->collect())->toMatchArray(['inodes_total' => 6553600, 'inodes_free' => 6141255]);
});

it('reports the inodes as unknown when they cannot be read', function () {
    app()->instance(Host::class, new FakeHost(inodes: null));

    $signal = app(Disk::class)->collect();

    expect($signal)->toMatchArray(['inodes_total' => null, 'inodes_free' => null])
        ->and($signal['free_bytes'])->toBeInt()
        ->and($signal['error'])->toBeNull();
});
