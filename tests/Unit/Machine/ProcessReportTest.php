<?php

use KobaltDigital\PingPong\Machine\ProcessReport;
use KobaltDigital\PingPong\Machine\Users;

function machineProcess(int $pid, int $uid, string $name, int $ticks, int $started = 100, int $rss = 1024, ?int $swap = null, bool $kernel = false): array
{
    return ['pid' => $pid, 'started' => $started, 'uid' => $uid, 'name' => $name, 'ticks' => $ticks, 'rss_bytes' => $rss, 'swap_bytes' => $swap, 'kernel' => $kernel];
}

function reportUsers(): Users
{
    $passwd = tempnam(sys_get_temp_dir(), 'passwd');
    file_put_contents($passwd, "root:x:0:0::/root:/bin/bash\nforge:x:1000:1000::/home/forge:/bin/bash\nshop:x:1001:1001::/home/shop:/bin/bash\n");

    return new Users($passwd, usePosix: false);
}

it('groups processes per user without CPU figures on the first tick', function () {
    $built = (new ProcessReport(reportUsers()))->build([
        machineProcess(1, 1000, 'php-fpm: forge', 500, rss: 100),
        machineProcess(2, 1000, 'php-fpm: forge', 400, rss: 200),
        machineProcess(3, 1001, 'php-fpm: shop', 10, rss: 50),
        machineProcess(4, 0, 'kworker/0:1', 0, rss: 0, kernel: true),
    ], null, 1000.0, 5000.0, true);

    expect($built['report']['interval_seconds'])->toBeNull()
        ->and($built['report']['cpu_seconds_total'])->toBeNull()
        ->and($built['report']['process_count'])->toBe(4)
        ->and($built['report']['scope'])->toBe('machine')
        ->and($built['report']['users'][0])->toBe([
            'user' => 'forge',
            'processes' => 2,
            'cpu_seconds' => null,
            'rss_bytes' => 300,
            'swap_bytes' => null,
            'names' => [['name' => 'php-fpm: forge', 'count' => 2]],
        ])
        ->and($built['snapshot']['ticks'])->toBe(['1:100' => 500, '2:100' => 400, '3:100' => 10, '4:100' => 0]);
});

it('counts the CPU each user used since the previous tick', function () {
    $previous = ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => ['1:100' => 500, '3:100' => 10]];

    $report = (new ProcessReport(reportUsers()))->build([
        machineProcess(1, 1000, 'php-fpm: forge', 3500),
        machineProcess(3, 1001, 'php-fpm: shop', 610),
        /* Started after the previous reading (at 4950 s): all of it is new. */
        machineProcess(5, 1001, 'php', 200, started: 495000),
        /* Was there before but not read then: left out. */
        machineProcess(6, 1001, 'php', 9000, started: 100),
    ], $previous, 1000.0, 5000.0, true)['report'];

    expect($report['interval_seconds'])->toBe(60)
        ->and($report['cpu_seconds_total'])->toBe(38.0)
        ->and(array_column($report['users'], 'cpu_seconds', 'user'))->toBe(['forge' => 30.0, 'shop' => 8.0]);
});

it('measures nothing against a reading from before a reboot or too long ago', function (float $takenAt, float $uptime) {
    $previous = ['taken_at' => $takenAt, 'uptime' => $uptime, 'ticks' => ['1:100' => 0]];

    $report = (new ProcessReport(reportUsers()))->build([machineProcess(1, 1000, 'php', 500)], $previous, 1000.0, 120.0, true)['report'];

    expect($report['cpu_seconds_total'])->toBeNull()
        ->and($report['interval_seconds'])->toBeNull();
})->with([
    'before a reboot' => [940.0, 90000.0],
    'too long ago' => [1.0, 100.0],
]);

it('names the user of a uid it cannot find by its number, and kernel threads as kernel', function () {
    $report = (new ProcessReport(reportUsers()))->build([
        machineProcess(1, 4242, 'node', 0, rss: 10),
        machineProcess(2, 0, 'kthreadd', 0, rss: 5, kernel: true),
    ], null, 1000.0, 5000.0, false)['report'];

    expect(array_column($report['users'], 'user'))->toBe(['4242', 'kernel'])
        ->and($report['scope'])->toBe('user');
});

it('sends the busiest users and the largest, never everyone', function () {
    $processes = [];

    foreach (range(1, 20) as $uid) {
        $processes[] = machineProcess($uid, 2000 + $uid, 'php', $uid * 100, rss: 1000 - $uid, swap: $uid === 20 ? 4096 : 0);
    }

    $previous = ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => array_fill_keys(array_map(fn (int $pid): string => "{$pid}:100", range(1, 20)), 0)];

    $users = (new ProcessReport(reportUsers()))->build($processes, $previous, 1000.0, 5000.0, true)['report']['users'];

    /* The eight busiest (13 to 20), the four largest (1 to 4); 20 also swaps most. */
    expect(array_column($users, 'user'))->toBe(['2020', '2019', '2018', '2017', '2016', '2015', '2014', '2013', '2004', '2003', '2002', '2001']);
});

it('keeps the four most common names of a user', function () {
    $processes = [];

    foreach (['php' => 5, 'node' => 4, 'bash' => 3, 'sshd' => 2, 'cron' => 1] as $name => $count) {
        foreach (range(1, $count) as $index) {
            $processes[] = machineProcess(count($processes) + 1, 1000, $name, 0);
        }
    }

    $names = (new ProcessReport(reportUsers()))->build($processes, null, 1000.0, 5000.0, true)['report']['users'][0]['names'];

    expect(array_column($names, 'name'))->toBe(['php', 'node', 'bash', 'sshd']);
});
