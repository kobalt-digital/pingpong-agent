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

it('finds an executable on the PATH without running anything', function () {
    $directory = sys_get_temp_dir().'/pingpong-bin-'.uniqid();
    mkdir($directory);
    touch("{$directory}/pm2");
    chmod("{$directory}/pm2", 0755);

    try {
        expect(withEnv(['PATH' => '/nonexistent'.PATH_SEPARATOR.$directory], fn () => (new Host)->executable('pm2')))
            ->toBe("{$directory}/pm2");
    } finally {
        unlink("{$directory}/pm2");
        rmdir($directory);
    }
})->skipOnWindows();

it('gives no executable when it is not on the PATH', function () {
    expect(withEnv(['PATH' => '/nonexistent'], fn () => (new Host)->executable('pm2')))->toBeNull();
});

it('knows a pid file of a live process runs', function () {
    $path = tempnam(sys_get_temp_dir(), 'pingpong-pid-');
    file_put_contents($path, getmypid()."\n");

    try {
        expect((new Host)->pidFileRuns($path))->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('knows a pid file that outlived its process does not run', function () {
    $path = tempnam(sys_get_temp_dir(), 'pingpong-pid-');
    file_put_contents($path, '99999999');

    try {
        expect((new Host)->pidFileRuns($path))->toBeFalse();
    } finally {
        unlink($path);
    }
})->skip(! is_dir('/proc') && ! function_exists('posix_kill'), 'Without /proc and ext-posix a pid file counts as running');

it('knows a pid file without a pid does not run', function (string $contents) {
    $path = tempnam(sys_get_temp_dir(), 'pingpong-pid-');
    file_put_contents($path, $contents);

    try {
        expect((new Host)->pidFileRuns($path))->toBeFalse();
    } finally {
        unlink($path);
    }
})->with([
    'an empty file' => '',
    'no number' => 'pm2',
]);

it('knows a missing pid file does not run', function () {
    expect((new Host)->pidFileRuns('/nonexistent/pm2.pid'))->toBeFalse();
});
