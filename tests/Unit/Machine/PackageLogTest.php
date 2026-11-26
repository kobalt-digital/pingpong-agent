<?php

use KobaltDigital\PingPong\Machine\PackageLog;

function packageLogDirectory(): string
{
    $directory = sys_get_temp_dir().'/pingpong-dpkg-'.bin2hex(random_bytes(4));
    mkdir($directory);

    return $directory;
}

function appendDpkg(string $log, string $lines): void
{
    file_put_contents($log, $lines, FILE_APPEND);
}

function aptUpgradeOfTwo(string $time = '2026-11-26 06:12'): string
{
    return <<<TXT
        {$time}:03 startup archives unpack
        {$time}:04 upgrade openssl:amd64 3.0.13-0ubuntu3.4 3.0.13-0ubuntu3.5
        {$time}:04 status half-configured openssl:amd64 3.0.13-0ubuntu3.4
        {$time}:04 status unpacked openssl:amd64 3.0.13-0ubuntu3.5
        {$time}:05 upgrade libssl3t64:amd64 3.0.13-0ubuntu3.4 3.0.13-0ubuntu3.5
        {$time}:06 startup packages configure
        {$time}:06 configure openssl:amd64 3.0.13-0ubuntu3.5 <none>
        {$time}:06 status installed openssl:amd64 3.0.13-0ubuntu3.5
        {$time}:07 trigproc libc-bin:amd64 2.39-0ubuntu8.3 <none>

        TXT;
}

it('starts at the end of the log, so a new install sends no history', function () {
    $directory = packageLogDirectory();
    appendDpkg("{$directory}/dpkg.log", aptUpgradeOfTwo());

    $read = (new PackageLog("{$directory}/dpkg.log", timezone: 'UTC'))->read(null);

    expect($read['changes'])->toBe([])
        ->and($read['cursor'])->toBe(['inode' => fileinode("{$directory}/dpkg.log"), 'offset' => filesize("{$directory}/dpkg.log")]);
});

it('sends what an apt upgrade of two packages changed once, with versions and the machine time', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    $packageLog = new PackageLog($log, timezone: 'Europe/Amsterdam');
    appendDpkg($log, "2026-11-25 10:00:00 startup archives unpack\n");
    $start = $packageLog->read(null)['cursor'];

    appendDpkg($log, aptUpgradeOfTwo());
    $first = $packageLog->read($start);
    $second = $packageLog->read($first['cursor']);

    expect($first['changes'])->toBe([
        ['at' => '2026-11-26T06:12:04+01:00', 'action' => 'upgrade', 'package' => 'openssl:amd64', 'from' => '3.0.13-0ubuntu3.4', 'to' => '3.0.13-0ubuntu3.5', 'by' => null],
        ['at' => '2026-11-26T06:12:05+01:00', 'action' => 'upgrade', 'package' => 'libssl3t64:amd64', 'from' => '3.0.13-0ubuntu3.4', 'to' => '3.0.13-0ubuntu3.5', 'by' => null],
    ])
        ->and($first['cursor']['offset'])->toBe(filesize($log))
        ->and($second['changes'])->toBe([]);
});

it('keeps installs, downgrades, removals and purges and passes over what changes no version', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    $packageLog = new PackageLog($log, timezone: 'Europe/Amsterdam');
    $start = $packageLog->read(null)['cursor'];

    appendDpkg($log, <<<'TXT'
        2026-07-02 18:25:20 install linux-image-6.8.0-50-generic:amd64 <none> 6.8.0-50.51
        2026-07-02 18:25:21 upgrade nginx:amd64 1.26.3-3+deb13u1 1.26.3-3
        2026-07-02 18:26:05 remove linux-image-6.8.0-45-generic:amd64 6.8.0-45.45 <none>
        2026-07-02 18:26:06 purge linux-image-6.8.0-45-generic:amd64 6.8.0-45.45 <none>
        2026-07-02 18:26:07 configure nginx:amd64 1.26.3-3 <none>
        2026-07-02 18:26:07 trigproc man-db:amd64 2.12.0-4 <none>
        2026-07-02 18:26:07 status installed nginx:amd64 1.26.3-3
        2026-07-02 18:26:08 startup packages configure
        not a dpkg line

        TXT);

    expect($packageLog->read($start)['changes'])->toBe([
        ['at' => '2026-07-02T18:25:20+02:00', 'action' => 'install', 'package' => 'linux-image-6.8.0-50-generic:amd64', 'from' => null, 'to' => '6.8.0-50.51', 'by' => null],
        ['at' => '2026-07-02T18:25:21+02:00', 'action' => 'downgrade', 'package' => 'nginx:amd64', 'from' => '1.26.3-3+deb13u1', 'to' => '1.26.3-3', 'by' => null],
        ['at' => '2026-07-02T18:26:05+02:00', 'action' => 'remove', 'package' => 'linux-image-6.8.0-45-generic:amd64', 'from' => '6.8.0-45.45', 'to' => null, 'by' => null],
        ['at' => '2026-07-02T18:26:06+02:00', 'action' => 'purge', 'package' => 'linux-image-6.8.0-45-generic:amd64', 'from' => '6.8.0-45.45', 'to' => null, 'by' => null],
    ]);
});

it('finishes the rotated log before it starts the new one, losing no line and sending none twice', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    $packageLog = new PackageLog($log, timezone: 'UTC');
    $start = $packageLog->read(null)['cursor'];

    appendDpkg($log, "2026-11-30 23:58:00 upgrade curl:amd64 8.5.0-2 8.5.0-3\n");
    $beforeRotation = $packageLog->read($start);
    appendDpkg($log, "2026-11-30 23:59:00 upgrade vim:amd64 2:9.1-1 2:9.1-2\n");
    rename($log, "{$log}.1");
    appendDpkg($log, "2026-12-01 00:01:00 upgrade git:amd64 1:2.43-1 1:2.43-2\n");

    $afterRotation = $packageLog->read($beforeRotation['cursor']);
    $next = $packageLog->read($afterRotation['cursor']);

    expect(array_column($beforeRotation['changes'], 'package'))->toBe(['curl:amd64'])
        ->and(array_column($afterRotation['changes'], 'package'))->toBe(['vim:amd64', 'git:amd64'])
        ->and($afterRotation['cursor'])->toBe(['inode' => fileinode($log), 'offset' => filesize($log)])
        ->and($next['changes'])->toBe([]);
});

it('reads a log that was truncated from its start', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    appendDpkg($log, aptUpgradeOfTwo());
    $packageLog = new PackageLog($log, timezone: 'UTC');
    $end = $packageLog->read(null)['cursor'];

    $handle = fopen($log, 'r+');
    ftruncate($handle, 0);
    fclose($handle);
    appendDpkg($log, "2026-11-26 07:00:00 upgrade git:amd64 1:2.43-1 1:2.43-2\n");

    expect(array_column($packageLog->read($end)['changes'], 'package'))->toBe(['git:amd64']);
});

it('sends at most a batch a tick and the rest in the ticks after', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    $packageLog = new PackageLog($log, timezone: 'UTC');
    $start = $packageLog->read(null)['cursor'];

    foreach (range(1, PackageLog::MAX_CHANGES + 50) as $number) {
        appendDpkg($log, "2026-11-26 06:00:00 install package-{$number}:amd64 <none> 1.0\n2026-11-26 06:00:01 configure package-{$number}:amd64 1.0 <none>\n");
    }

    $first = $packageLog->read($start);
    $second = $packageLog->read($first['cursor']);

    expect($first['changes'])->toHaveCount(PackageLog::MAX_CHANGES)
        ->and($first['changes'][PackageLog::MAX_CHANGES - 1]['package'])->toBe('package-'.PackageLog::MAX_CHANGES.':amd64')
        ->and(array_column($second['changes'], 'package'))->toBe(array_map(fn (int $number) => "package-{$number}:amd64", range(PackageLog::MAX_CHANGES + 1, PackageLog::MAX_CHANGES + 50)))
        ->and($second['cursor']['offset'])->toBe(filesize($log));
});

it('stays in the rotated log while the batch is full', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    $packageLog = new PackageLog($log, timezone: 'UTC');
    $start = $packageLog->read(null)['cursor'];

    foreach (range(1, PackageLog::MAX_CHANGES + 1) as $number) {
        appendDpkg($log, "2026-11-30 23:00:00 install package-{$number}:amd64 <none> 1.0\n");
    }

    rename($log, "{$log}.1");
    appendDpkg($log, "2026-12-01 00:01:00 upgrade git:amd64 1:2.43-1 1:2.43-2\n");

    $first = $packageLog->read($start);
    $second = $packageLog->read($first['cursor']);

    expect($first['changes'])->toHaveCount(PackageLog::MAX_CHANGES)
        ->and($first['cursor']['inode'])->toBe(fileinode("{$log}.1"))
        ->and(array_column($second['changes'], 'package'))->toBe(['package-'.(PackageLog::MAX_CHANGES + 1).':amd64', 'git:amd64']);
});

it('leaves a line dpkg is still writing for the next tick', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    $packageLog = new PackageLog($log, timezone: 'UTC');
    $start = $packageLog->read(null)['cursor'];

    appendDpkg($log, '2026-11-26 06:00:00 upgrade git:amd64 1:2.43-1');
    $partial = $packageLog->read($start);
    appendDpkg($log, " 1:2.43-2\n");

    expect($partial['changes'])->toBe([])
        ->and($partial['cursor'])->toBe($start)
        ->and(array_column($packageLog->read($partial['cursor'])['changes'], 'to'))->toBe(['1:2.43-2']);
});

it('reads nothing where dpkg keeps no log', function () {
    expect((new PackageLog('/does/not/exist/dpkg.log'))->read(['inode' => 1, 'offset' => 0]))->toBeNull();
});

it('starts at the end again when what it kept is no cursor', function (mixed $cursor) {
    $directory = packageLogDirectory();
    appendDpkg("{$directory}/dpkg.log", aptUpgradeOfTwo());

    expect((new PackageLog("{$directory}/dpkg.log", timezone: 'UTC'))->read($cursor)['changes'])->toBe([]);
})->with([
    'nothing' => [null],
    'a string' => ['garbage'],
    'a negative offset' => [['inode' => 1, 'offset' => -1]],
]);

it('writes the time in the zone the machine runs in', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    symlink('/usr/share/zoneinfo/America/New_York', "{$directory}/localtime");
    file_put_contents("{$directory}/timezone", "Asia/Tokyo\n");
    $start = (new PackageLog($log))->read(null)['cursor'];
    appendDpkg($log, "2026-11-26 06:00:00 upgrade git:amd64 1:2.43-1 1:2.43-2\n");

    $fromLink = new PackageLog($log, localtime: "{$directory}/localtime", timezoneFile: "{$directory}/timezone");
    $fromFile = new PackageLog($log, localtime: "{$directory}/none", timezoneFile: "{$directory}/timezone");
    $neither = new PackageLog($log, localtime: "{$directory}/none", timezoneFile: "{$directory}/none");

    expect($fromLink->read($start)['changes'][0]['at'])->toBe('2026-11-26T06:00:00-05:00')
        ->and($fromFile->read($start)['changes'][0]['at'])->toBe('2026-11-26T06:00:00+09:00')
        ->and($neither->read($start)['changes'][0]['at'])->toBe('2026-11-26T06:00:00+00:00');
});

it('sends an upgrade to an older version as a downgrade and a reinstall as an upgrade', function () {
    $directory = packageLogDirectory();
    $log = "{$directory}/dpkg.log";
    touch($log);
    $packageLog = new PackageLog($log, timezone: 'UTC');
    $start = $packageLog->read(null)['cursor'];

    appendDpkg($log, "2026-11-26 06:00:00 upgrade nginx:amd64 1.26.3-3+deb13u1 1.26.3-3\n2026-11-26 06:00:01 upgrade git:amd64 1:2.43-1 1:2.43-1\n");

    expect(array_column($packageLog->read($start)['changes'], 'action', 'package'))->toBe(['nginx:amd64' => 'downgrade', 'git:amd64' => 'upgrade']);
});
