<?php

use KobaltDigital\PingPong\Machine\ProcessTable;

/**
 * A line of /proc/<pid>/stat with the fields the table reads: flags (9),
 * utime (14), stime (15), starttime (22) and rss (24).
 */
function statLine(int $pid, string $name, int $utime, int $stime, int $started, int $rssPages, int $flags = 0): string
{
    $fields = array_fill(3, 50, '0');
    $fields[3] = 'S';
    $fields[9] = (string) $flags;
    $fields[14] = (string) $utime;
    $fields[15] = (string) $stime;
    $fields[22] = (string) $started;
    $fields[24] = (string) $rssPages;

    return "{$pid} ({$name}) ".implode(' ', $fields)."\n";
}

function fakeProc(array $processes): string
{
    $proc = sys_get_temp_dir().'/pingpong-proc-'.bin2hex(random_bytes(4));
    mkdir($proc);
    file_put_contents("{$proc}/uptime", "5000.25 70000.00\n");

    foreach ($processes as $pid => $process) {
        mkdir("{$proc}/{$pid}");
        file_put_contents("{$proc}/{$pid}/stat", $process['stat']);
        file_put_contents("{$proc}/{$pid}/cmdline", $process['cmdline'] ?? '');
        file_put_contents("{$proc}/{$pid}/status", $process['status'] ?? '');
    }

    return $proc;
}

it('reads the name, CPU ticks, memory and start of a process', function () {
    $process = (new ProcessTable)->parseStat(statLine(42, 'php-fpm8.3', 120, 30, 9000, 2560));

    expect($process)->toBe([
        'name' => 'php-fpm8.3',
        'ticks' => 150,
        'rss_pages' => 2560,
        'started' => 9000,
        'kernel' => false,
    ]);
});

it('reads a name that holds spaces and parentheses', function () {
    expect((new ProcessTable)->parseStat(statLine(7, 'tmux: server (1)', 1, 2, 3, 4))['name'])->toBe('tmux: server (1)');
});

it('marks kernel threads by their flag', function () {
    expect((new ProcessTable)->parseStat(statLine(2, 'kthreadd', 0, 0, 0, 0, 0x00200000))['kernel'])->toBeTrue();
});

it('gives no process for a stat line it cannot read', function () {
    expect((new ProcessTable)->parseStat('garbage'))->toBeNull()
        ->and((new ProcessTable)->parseStat('1 (init) S 0 0'))->toBeNull();
});

it('keeps only the pool of php-fpm, never the rest of a command line', function () {
    $table = new ProcessTable;

    expect($table->fpmName("php-fpm: pool despreker\0\0"))->toBe('php-fpm: despreker')
        ->and($table->fpmName('php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)'))->toBe('php-fpm: master')
        ->and($table->fpmName("php-fpm8.3\0--secret=hunter2"))->toBeNull();
});

it('reads the swap of a process from its status', function () {
    expect((new ProcessTable)->parseSwap("Name:\tmysqld\nVmSwap:\t    2048 kB\n"))->toBe(2048 * 1024)
        ->and((new ProcessTable)->parseSwap("Name:\tkthreadd\n"))->toBeNull();
});

it('reads every process of a proc directory, with the php-fpm pool and swap only when asked', function () {
    $proc = fakeProc([
        10 => ['stat' => statLine(10, 'php-fpm8.3', 100, 50, 1000, 1000), 'cmdline' => "php-fpm: pool shop\0", 'status' => "VmSwap:\t 4 kB\n"],
        11 => ['stat' => statLine(11, 'mysqld', 10, 5, 500, 2000), 'status' => "VmSwap:\t 8 kB\n"],
    ]);
    mkdir("{$proc}/self-not-a-pid");

    $table = new ProcessTable($proc);
    $withoutSwap = $table->read(false);
    $withSwap = $table->read(true);

    expect($withoutSwap)->toHaveCount(2)
        ->and(array_column($withoutSwap, 'name'))->toEqualCanonicalizing(['php-fpm: shop', 'mysqld'])
        ->and(array_column($withoutSwap, 'swap_bytes'))->toBe([null, null])
        ->and(array_column($withSwap, 'swap_bytes'))->toEqualCanonicalizing([4096, 8192])
        ->and($withSwap[0]['uid'])->toBe(fileowner("{$proc}/10"))
        ->and($table->uptime())->toBe(5000.25);
});

it('gives no table when proc cannot be read', function () {
    expect((new ProcessTable('/does/not/exist'))->read(false))->toBeNull()
        ->and((new ProcessTable('/does/not/exist'))->seesEveryone())->toBeFalse();
});
