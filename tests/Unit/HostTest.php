<?php

use Illuminate\Support\Facades\Process;
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

it('parses the counters of /proc/vmstat', function () {
    $contents = <<<'VMSTAT'
    nr_free_pages 213400
    pgpgin 182736451
    pgpgout 349182736
    pswpin 1843
    pswpout 5120
    pgfault 9182736451
    oom_kill 2
    VMSTAT;

    expect((new Host)->parseVmStat($contents))->toBe([
        'oom_kills' => 2,
        'swap_in_pages' => 1843,
        'swap_out_pages' => 5120,
    ]);
});

it('leaves a counter /proc/vmstat lacks unknown', function () {
    expect((new Host)->parseVmStat("nr_free_pages 213400\npswpin 0\npswpout 0\n"))->toBe([
        'oom_kills' => null,
        'swap_in_pages' => 0,
        'swap_out_pages' => 0,
    ]);
});

it('parses the cpu time of all cpus from /proc/stat', function () {
    $contents = <<<'STAT'
    cpu  15595036 4132254 8155061 341748387 347222 0 1950356 1013901 0 0
    cpu0 1949379 516531 1019382 42718548 43402 0 243794 126737 0 0
    cpu1 1950012 516612 1019233 42719001 43399 0 243803 126741 0 0
    intr 1234567890 0 9 0 0 0
    ctxt 9876543210
    btime 1762300000
    STAT;

    expect((new Host)->parseCpuTimes($contents))->toBe([
        'user' => 15595036,
        'nice' => 4132254,
        'system' => 8155061,
        'idle' => 341748387,
        'iowait' => 347222,
        'irq' => 0,
        'softirq' => 1950356,
        'steal' => 1013901,
    ]);
});

it('gives no cpu time when /proc/stat has no total line', function (string $contents) {
    expect((new Host)->parseCpuTimes($contents))->toBeNull();
})->with([
    'only per cpu lines' => "cpu0 1949379 516531 1019382 42718548 43402 0 243794 126737 0 0\n",
    'fewer than eight columns' => "cpu  15595036 4132254 8155061 341748387\n",
]);

it('parses avg60 from a /proc/pressure file', function () {
    $contents = <<<'PRESSURE'
    some avg10=36.48 avg60=24.11 avg300=8.22 total=3333804749
    full avg10=0.00 avg60=0.00 avg300=0.00 total=0
    PRESSURE;

    expect((new Host)->parsePressure($contents))->toBe(['some' => 24.11, 'full' => 0.0]);
});

it('parses a /proc/pressure/cpu file of a kernel that has no full line', function () {
    expect((new Host)->parsePressure("some avg10=1.05 avg60=12.50 avg300=3.40 total=918273645\n"))->toBe(['some' => 12.5]);
});

it('reads pressure on this machine or gives null', function () {
    $pressure = (new Host)->pressure();

    expect($pressure === null || array_keys($pressure) === ['cpu_some', 'memory_some', 'memory_full', 'io_some', 'io_full'])->toBeTrue();
});

it('parses the inodes from df -iP', function () {
    $output = <<<'DF'
    Filesystem       Inodes    IUsed    IFree IUse% Mounted on
    /dev/vda4      41542208 23069182 18473026   56% /
    DF;

    expect((new Host)->parseInodes($output))->toBe(['total' => 41542208, 'free' => 18473026]);
});

it('parses the inodes of a mount point with spaces', function () {
    $output = "Filesystem Inodes IUsed IFree IUse% Mounted on\n/dev/sdb1 655360 12 655348 1% /mnt/app data\n";

    expect((new Host)->parseInodes($output))->toBe(['total' => 655360, 'free' => 655348]);
});

it('gives no inodes when df cannot tell', function (string $output) {
    expect((new Host)->parseInodes($output))->toBeNull();
})->with([
    'btrfs reports none' => "Filesystem Inodes IUsed IFree IUse% Mounted on\n/dev/nvme0n1p2 0 0 0 - /\n",
    'a dash for every column' => "Filesystem Inodes IUsed IFree IUse% Mounted on\noverlay - - - - /\n",
    'blocks before inodes (macOS)' => "Filesystem 512-blocks Used Available Capacity iused ifree %iused Mounted on\n/dev/disk3s1s1 965595304 20406048 482211424 5% 404167 2411057120 0% /\n",
    'no data line' => "Filesystem Inodes IUsed IFree IUse% Mounted on\n",
    'an error' => "df: /nonexistent: No such file or directory\n",
]);

it('runs df on the path without a shell', function () {
    Process::fake(['*' => Process::result("Filesystem Inodes IUsed IFree IUse% Mounted on\n/dev/vda4 41542208 23069182 18473026 56% /\n")]);

    $inodes = withEnv(['PATH' => dirname(dfBinary())], fn () => (new Host)->inodes('/srv/app with space'));

    expect($inodes)->toBe(['total' => 41542208, 'free' => 18473026]);

    Process::assertRan(fn ($process) => $process->command === [dfBinary(), '-iP', '/srv/app with space']
        && $process->environment === ['LC_ALL' => 'C']
        && $process->timeout === Host::DF_TIMEOUT_SECONDS);
})->skip(fn () => dfBinary() === null, 'df is not installed');

it('gives no inodes when df fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'df: /srv/app: No such file or directory', exitCode: 1)]);

    expect(withEnv(['PATH' => dirname(dfBinary())], fn () => (new Host)->inodes('/srv/app')))->toBeNull();
})->skip(fn () => dfBinary() === null, 'df is not installed');

it('gives no inodes and runs nothing when df is not on the PATH', function () {
    Process::fake();

    expect(withEnv(['PATH' => '/nonexistent'], fn () => (new Host)->inodes('/srv/app')))->toBeNull();

    Process::assertNothingRan();
});

it('parses a machine id', function () {
    expect((new Host)->parseMachineId("D66E4F3DD9E04E13B9B2ABEEE6709354\n"))->toBe('d66e4f3dd9e04e13b9b2abeee6709354');
});

it('gives no machine id for anything but 32 hex characters', function (string $contents) {
    expect((new Host)->parseMachineId($contents))->toBeNull();
})->with([
    'empty' => '',
    'not yet set on first boot' => "uninitialized\n",
    'too short' => "d66e4f3dd9e04e13b9b2abeee670935\n",
    'a uuid with dashes' => "d66e4f3d-d9e0-4e13-b9b2-abeee6709354\n",
]);

it('reads a machine id on this machine or gives null', function () {
    $machineId = (new Host)->machineId();

    expect($machineId === null || preg_match('/^[0-9a-f]{32}$/', $machineId) === 1)->toBeTrue();
});

it('parses the os name', function (string $contents, string $osName) {
    expect((new Host)->parseOsName($contents))->toBe($osName);
})->with([
    'double quoted' => ["NAME=\"Ubuntu\"\nPRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\nVERSION_ID=\"24.04\"\n", 'Ubuntu 24.04.1 LTS'],
    'single quoted' => ["PRETTY_NAME='Debian GNU/Linux 12 (bookworm)'\n", 'Debian GNU/Linux 12 (bookworm)'],
    'bare' => ["ID=alpine\nPRETTY_NAME=Alpine\n", 'Alpine'],
    'escaped quote' => ["PRETTY_NAME=\"Acme \\\"Edge\\\" 1.0\"\n", 'Acme "Edge" 1.0'],
    'padded, with a carriage return' => ["PRETTY_NAME=\"  Rocky Linux 9.4 (Blue Onyx)  \"\r\n", 'Rocky Linux 9.4 (Blue Onyx)'],
]);

it('cuts the os name to 100 characters', function () {
    $osName = (new Host)->parseOsName('PRETTY_NAME="'.str_repeat('a', 150).'"');

    expect($osName)->toBe(str_repeat('a', Host::MAX_NAME_LENGTH));
});

it('gives no os name without a pretty name', function (string $contents) {
    expect((new Host)->parseOsName($contents))->toBeNull();
})->with([
    'empty file' => '',
    'missing PRETTY_NAME' => "NAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\n",
    'empty PRETTY_NAME' => "PRETTY_NAME=\"\"\n",
    'only in a comment' => "# PRETTY_NAME=\"Ubuntu\"\n",
]);

it('reads the os name on this machine or gives null', function () {
    $osName = (new Host)->osName();

    expect($osName === null || ($osName !== '' && mb_strlen($osName) <= Host::MAX_NAME_LENGTH))->toBeTrue();
});

it('reads the kernel release of this machine', function () {
    expect((new Host)->kernel())->toBe(php_uname('r') === '' ? null : mb_substr(php_uname('r'), 0, Host::MAX_NAME_LENGTH));
});

function dfBinary(): ?string
{
    return (new Host)->executable('df');
}
