<?php

use KobaltDigital\PingPong\Host;

it('parses /proc/meminfo into bytes', function () {
    $contents = <<<'MEMINFO'
    MemTotal:        8051960 kB
    MemFree:          213400 kB
    MemAvailable:    1280000 kB
    Buffers:          102400 kB
    SwapTotal:       2097148 kB
    SwapFree:        1048574 kB
    MEMINFO;

    expect((new Host)->parseMemInfo($contents))->toBe([
        'total_bytes' => 8051960 * 1024,
        'available_bytes' => 1280000 * 1024,
        'swap_used_bytes' => (2097148 - 1048574) * 1024,
    ]);
});

it('gives no memory when /proc/meminfo lacks a key', function () {
    expect((new Host)->parseMemInfo("MemTotal: 8051960 kB\n"))->toBeNull();
});

it('parses /proc/loadavg', function () {
    expect((new Host)->parseLoadAverage("0.81 0.62 0.55 2/512 12345\n"))->toBe([
        '1m' => 0.81,
        '5m' => 0.62,
        '15m' => 0.55,
    ]);
});

it('gives no load when /proc/loadavg is malformed', function () {
    expect((new Host)->parseLoadAverage("garbage\n"))->toBeNull();
});

it('counts processors in /proc/cpuinfo', function () {
    $contents = "processor\t: 0\nmodel name\t: x\n\nprocessor\t: 1\nmodel name\t: x\n";

    expect((new Host)->parseCores($contents))->toBe(2);
});

it('gives no cores when /proc/cpuinfo has no processors', function () {
    expect((new Host)->parseCores("vendor_id\t: x\n"))->toBeNull();
});

it('reads a load average on this machine or gives null', function () {
    $load = (new Host)->loadAverage();

    expect($load === null || array_keys($load) === ['1m', '5m', '15m'])->toBeTrue();
});
