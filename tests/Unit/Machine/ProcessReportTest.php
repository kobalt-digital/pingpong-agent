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
        machineProcess(1, 1000, 'php-fpm', 500, rss: 100),
        machineProcess(2, 1000, 'php-fpm', 400, rss: 200),
        machineProcess(3, 1001, 'php-fpm', 10, rss: 50),
        machineProcess(4, 0, 'kworker-0:1', 0, rss: 0, kernel: true),
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
            'names' => [['name' => 'php-fpm', 'count' => 2, 'cpu_seconds' => null, 'rss_bytes' => 300]],
        ])
        ->and($built['snapshot']['ticks'])->toBe(['1:100' => 500, '2:100' => 400, '3:100' => 10, '4:100' => 0]);
});

it('counts CPU and RSS per user and per process name across ticks, including PID churn', function () {
    /* PID 2 exited between ticks; PID 10 was reused with a new start time; PID 5 spawned after the previous reading. */
    $previous = [
        'taken_at' => 940.0,
        'uptime' => 4940.0,
        'ticks' => [
            '1:100' => 500,
            '2:100' => 900,
            '3:100' => 10,
            '10:100' => 800,
        ],
    ];

    $report = (new ProcessReport(reportUsers()))->build([
        machineProcess(1, 1000, 'php-fpm', 3500, rss: 20_000_000),
        machineProcess(10, 1000, 'php-fpm', 250, started: 496000, rss: 15_000_000),
        machineProcess(3, 1001, 'php artisan horizon', 610, rss: 50_000_000),
        /* Started after the previous reading (at 4950 s): all of it is new. */
        machineProcess(5, 1001, 'php artisan queue:work', 200, started: 495000, rss: 30_000_000),
        /* Was there before but not read then: left out of CPU delta, still counts in count & RSS. */
        machineProcess(6, 1001, 'php artisan queue:work', 9000, started: 100, rss: 25_000_000),
    ], $previous, 1000.0, 5000.0, true)['report'];

    expect($report['interval_seconds'])->toBe(60)
        ->and($report['cpu_seconds_total'])->toBe(40.5)
        ->and(array_column($report['users'], 'cpu_seconds', 'user'))->toBe(['forge' => 32.5, 'shop' => 8.0])
        ->and($report['users'][0]['names'])->toBe([
            ['name' => 'php-fpm', 'count' => 2, 'cpu_seconds' => 32.5, 'rss_bytes' => 35_000_000],
        ])
        ->and($report['users'][1]['names'])->toBe([
            ['name' => 'php artisan horizon', 'count' => 1, 'cpu_seconds' => 6.0, 'rss_bytes' => 50_000_000],
            ['name' => 'php artisan queue:work', 'count' => 2, 'cpu_seconds' => 2.0, 'rss_bytes' => 55_000_000],
        ]);
});

it('measures nothing against a reading from before a reboot or too long ago', function (float $takenAt, float $uptime) {
    $previous = ['taken_at' => $takenAt, 'uptime' => $uptime, 'ticks' => ['1:100' => 0]];

    $report = (new ProcessReport(reportUsers()))->build([machineProcess(1, 1000, 'php', 500)], $previous, 1000.0, 120.0, true)['report'];

    expect($report['cpu_seconds_total'])->toBeNull()
        ->and($report['interval_seconds'])->toBeNull()
        ->and($report['users'][0]['names'][0]['cpu_seconds'])->toBeNull();
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

it('sorts discovered process groups by cpu_seconds desc then rss_bytes desc and caps at 4 without watches', function () {
    $processes = [
        machineProcess(1, 1000, 'proc-a', 50, rss: 500),
        machineProcess(2, 1000, 'proc-b', 300, rss: 100),
        machineProcess(3, 1000, 'proc-c', 200, rss: 100),
        machineProcess(4, 1000, 'proc-d', 200, rss: 900),
        machineProcess(5, 1000, 'proc-e', 100, rss: 400),
        machineProcess(6, 1000, 'proc-f', 100, rss: 800),
    ];

    $previous = ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => array_fill_keys(array_map(fn (int $pid): string => "{$pid}:100", range(1, 6)), 0)];

    $names = (new ProcessReport(reportUsers()))->build($processes, $previous, 1000.0, 5000.0, true)['report']['users'][0]['names'];

    expect(array_column($names, 'name'))->toBe([
        'proc-b',
        'proc-d',
        'proc-c',
        'proc-f',
    ]);
});

it('always reports watched users and watched processes including stopped ones', function () {
    $processes = [
        /* Low CPU so it falls outside the top 4 discovered names, proving watch_processes includes it anyway. */
        machineProcess(1, 1000, 'node server.js', 10, rss: 40_000_000),
    ];

    foreach (range(2, 7) as $index) {
        $processes[] = machineProcess($index, 1000, "busy-{$index}", $index * 100, rss: $index * 1000);
    }

    $previous = ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => array_fill_keys(array_map(fn (int $pid): string => "{$pid}:100", range(1, 7)), 0)];

    $report = (new ProcessReport(reportUsers()))->build(
        $processes,
        $previous,
        1000.0,
        5000.0,
        true,
        watchUsers: ['shop'],
        watchProcesses: [
            ['user' => 'forge', 'process' => 'node server.js'],
            ['user' => 'forge', 'process' => 'busy-7'],
            ['user' => 'forge', 'process' => 'php artisan horizon'],
            ['user' => 'root', 'process' => 'mysqld'],
        ],
    )['report'];

    $byUser = array_column($report['users'], null, 'user');

    expect(array_keys($byUser))->toEqualCanonicalizing(['forge', 'shop', 'root'])
        ->and($byUser['shop'])->toBe([
            'user' => 'shop',
            'processes' => 0,
            'cpu_seconds' => 0.0,
            'rss_bytes' => 0,
            'swap_bytes' => 0,
            'names' => [],
        ])
        ->and($byUser['root'])->toBe([
            'user' => 'root',
            'processes' => 0,
            'cpu_seconds' => 0.0,
            'rss_bytes' => 0,
            'swap_bytes' => 0,
            'names' => [
                ['name' => 'mysqld', 'count' => 0, 'cpu_seconds' => 0.0, 'rss_bytes' => 0],
            ],
        ]);

    $forgeNames = array_column($byUser['forge']['names'], null, 'name');

    /* Top 4 discovered (busy-7..busy-4; busy-7 is also watched and not duplicated) + watched node server.js + stopped horizon. */
    expect($byUser['forge']['names'])->toHaveCount(6)
        ->and($forgeNames['node server.js'])->toBe(['name' => 'node server.js', 'count' => 1, 'cpu_seconds' => 0.1, 'rss_bytes' => 40_000_000])
        ->and($forgeNames['php artisan horizon'])->toBe(['name' => 'php artisan horizon', 'count' => 0, 'cpu_seconds' => 0.0, 'rss_bytes' => 0]);
});

it('matches php-fpm pool entries against a php-fpm watch without adding a zero-count duplicate', function () {
    $processes = [
        /* Both FPM pools have lower CPU than the 4 busy processes so they fall outside the top 4. */
        machineProcess(1, 1000, 'php-fpm: mytiemlin', 20, rss: 30_000_000),
        machineProcess(2, 1000, 'php-fpm: mytiemlin', 30, rss: 20_000_000),
        machineProcess(3, 1000, 'php-fpm: admin', 10, rss: 15_000_000),
        machineProcess(4, 1000, 'busy-1', 500, rss: 1000),
        machineProcess(5, 1000, 'busy-2', 400, rss: 1000),
        machineProcess(6, 1000, 'busy-3', 300, rss: 1000),
        machineProcess(7, 1000, 'busy-4', 200, rss: 1000),
    ];

    $previous = ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => array_fill_keys(array_map(fn (int $pid): string => "{$pid}:100", range(1, 7)), 0)];

    $report = (new ProcessReport(reportUsers()))->build(
        $processes,
        $previous,
        1000.0,
        5000.0,
        true,
        watchProcesses: [
            ['user' => 'forge', 'process' => 'php-fpm'],
            ['user' => 'shop', 'process' => 'php-fpm'],
        ],
    )['report'];

    $byUser = array_column($report['users'], null, 'user');
    $forgeNames = array_column($byUser['forge']['names'], null, 'name');

    expect($byUser['forge']['names'])->toHaveCount(6)
        ->and($forgeNames)->not->toHaveKey('php-fpm')
        ->and($forgeNames['php-fpm: mytiemlin'])->toBe(['name' => 'php-fpm: mytiemlin', 'count' => 2, 'cpu_seconds' => 0.5, 'rss_bytes' => 50_000_000])
        ->and($forgeNames['php-fpm: admin'])->toBe(['name' => 'php-fpm: admin', 'count' => 1, 'cpu_seconds' => 0.1, 'rss_bytes' => 15_000_000])
        ->and($byUser['shop']['names'])->toBe([
            ['name' => 'php-fpm', 'count' => 0, 'cpu_seconds' => 0.0, 'rss_bytes' => 0],
        ]);
});

it('reports null cpu_seconds for stopped watched users and processes on the first tick', function () {
    $report = (new ProcessReport(reportUsers()))->build(
        [],
        null,
        1000.0,
        5000.0,
        true,
        watchUsers: ['shop'],
        watchProcesses: [['user' => 'shop', 'process' => 'php artisan horizon']],
    )['report'];

    expect($report['users'])->toBe([
        [
            'user' => 'shop',
            'processes' => 0,
            'cpu_seconds' => null,
            'rss_bytes' => 0,
            'swap_bytes' => 0,
            'names' => [
                ['name' => 'php artisan horizon', 'count' => 0, 'cpu_seconds' => null, 'rss_bytes' => 0],
            ],
        ],
    ]);
});

it('restricts watched users and processes to current user when scope is user', function () {
    $report = (new ProcessReport(reportUsers()))->build(
        [],
        ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => []],
        1000.0,
        5000.0,
        false,
        watchUsers: ['forge', 'root', 'shop'],
        watchProcesses: [
            ['user' => 'forge', 'process' => 'php artisan horizon'],
            ['user' => 'root', 'process' => 'mysqld'],
        ],
        currentUser: 'forge',
    )['report'];

    expect($report['scope'])->toBe('user')
        ->and($report['users'])->toBe([
            [
                'user' => 'forge',
                'processes' => 0,
                'cpu_seconds' => 0.0,
                'rss_bytes' => 0,
                'swap_bytes' => 0,
                'names' => [
                    ['name' => 'php artisan horizon', 'count' => 0, 'cpu_seconds' => 0.0, 'rss_bytes' => 0],
                ],
            ],
        ]);
});

it('caps reported users at 128 and names per user at 32', function () {
    $watchUsers = array_map(fn (int $i): string => sprintf('user-%03d', $i), range(1, 140));
    $watchProcesses = array_map(fn (int $i): array => ['user' => 'forge', 'process' => sprintf('worker-%02d', $i)], range(1, 40));

    $report = (new ProcessReport(reportUsers()))->build(
        [machineProcess(1, 1000, 'php-fpm', 100, rss: 1000)],
        ['taken_at' => 940.0, 'uptime' => 4940.0, 'ticks' => ['1:100' => 0]],
        1000.0,
        5000.0,
        true,
        watchUsers: $watchUsers,
        watchProcesses: $watchProcesses,
    )['report'];

    $byUser = array_column($report['users'], null, 'user');

    expect($report['users'])->toHaveCount(128)
        ->and($byUser['forge']['names'])->toHaveCount(32);
});
