<?php

use KobaltDigital\PingPong\Machine\ProcessTable;

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

it('preserves php-fpm pool and master names without leaking other arguments', function (string $comm, string $cmdline, string $expected) {
    expect((new ProcessTable)->normalizeName($comm, $cmdline))->toBe($expected);
})->with([
    'pool worker' => ['php-fpm8.4', "php-fpm: pool mytiemlin\0\0", 'php-fpm: mytiemlin'],
    'master process' => ['php-fpm8.4', 'php-fpm8.4: master process (/etc/php/8.4/fpm/php-fpm.conf)', 'php-fpm: master'],
    'pool with slash sanitized' => ['php-fpm8.4', "php-fpm: pool site/sub\0", 'php-fpm: site-sub'],
    'binary path' => ['master', "/usr/sbin/php-fpm8.4\0--nodaemonize\0", 'php-fpm'],
    'comm only without cmdline' => ['php-fpm8.3', '', 'php-fpm'],
    'secrets in cmdline' => ['php-fpm8.3', "php-fpm8.3\0--secret=hunter2", 'php-fpm'],
]);

it('normalizes PHP artisan, Node and Python scripts cleanly', function (string $comm, string $cmdline, string $expected) {
    expect((new ProcessTable)->normalizeName($comm, $cmdline))->toBe($expected);
})->with([
    'php artisan horizon' => ['php8.4', "php8.4\0artisan\0horizon\0", 'php artisan horizon'],
    'full path php artisan queue:work with flags' => ['php8.4', "/usr/bin/php8.4\0/var/www/site/artisan\0queue:work\0--tries=3\0--sleep=3\0", 'php artisan queue:work'],
    'php with -d option before artisan' => ['php', "/usr/bin/php\0-d\0memory_limit=512M\0/srv/app/artisan\0reverb:start\0", 'php artisan reverb:start'],
    'php artisan schedule:run' => ['php', "php\0artisan\0schedule:run\0", 'php artisan schedule:run'],
    'php artisan without subcommand falls back to comm' => ['php8.4', "php8.4\0artisan\0--version\0", 'php8.4'],
    'php inline code does not match artisan' => ['php', "php\0-r\0echo 'artisan horizon';\0", 'php'],
    'non-artisan php script falls back to comm' => ['php', "php\0/usr/bin/composer\0artisan\0horizon\0", 'php'],
    'node script' => ['node', "/usr/bin/node\0/app/server.js\0--port=3000\0", 'node server.js'],
    'node with flags and preload before script' => ['node', "/usr/bin/node\0--max-old-space-size=2048\0-r\0dotenv/config\0/app/dist/worker.mjs\0", 'node worker.mjs'],
    'node inline eval falls back to node' => ['MainThread', "/usr/bin/node\0-e\0console.log('secret')\0", 'node'],
    'node bundled -pe inline code falls back to node' => ['node', "/usr/bin/node\0-pe\0process.env.SECRET\0", 'node'],
    'node --eval= falls back to node' => ['node', "/usr/bin/node\0--eval=console.log(1)\0", 'node'],
    'bare node falls back to node' => ['node', "/usr/bin/node\0", 'node'],
    'python3 script' => ['python3', "/usr/bin/python3\0-u\0/opt/worker/sync.py\0--daemon\0", 'python sync.py'],
    'python3 with attached -W value before script' => ['python3', "/usr/bin/python3\0-Wonce\0/opt/worker/sync.py\0", 'python sync.py'],
    'versioned python script' => ['python3.12', "/usr/bin/python3.12\0/srv/ingest.py\0", 'python ingest.py'],
    'python inline -c falls back to comm' => ['python3', "/usr/bin/python3\0-c\0import sys; print(sys.version)\0", 'python3'],
    'python attached -c inline code falls back to comm' => ['python3', "/usr/bin/python3\0-cprint(123)\0", 'python3'],
    'python bundled -uc inline code falls back to comm' => ['python3', "/usr/bin/python3\0-uc\0print(123)\0", 'python3'],
    'python bundled -um module falls back to comm' => ['python3', "/usr/bin/python3\0-um\0http.server\0", 'python3'],
    'python --command falls back to comm' => ['python3', "/usr/bin/python3\0--command\0print(123)\0", 'python3'],
    'plain mysqld uses comm' => ['mysqld', "/usr/sbin/mysqld\0--user=mysql\0", 'mysqld'],
    'plain redis-server uses comm' => ['redis-server', "redis-server *:6379\0", 'redis-server'],
]);

it('sanitizes slashes, control characters, whitespace and caps names at 64 chars', function () {
    $table = new ProcessTable;

    expect($table->normalizeName('kworker/0:1', ''))->toBe('kworker-0:1')
        ->and($table->normalizeName("  nginx\t\x07\n ", ''))->toBe('nginx')
        ->and($table->normalizeName('php', "php\0artisan\0app:sync/v2\0"))->toBe('php artisan app:sync-v2')
        ->and(strlen($table->normalizeName('php', "php\0artisan\0".str_repeat('a', 80))))->toBe(64)
        ->and(strlen($table->normalizeName('php-fpm8.4', 'php-fpm: pool '.str_repeat('long-domain.', 10).'nl')))->toBe(64)
        ->and($table->normalizeName('php', "php\0artisan\0".str_repeat('a', 52).'   extra'))->not->toEndWith(' ');
});

it('reads the swap of a process from its status', function () {
    expect((new ProcessTable)->parseSwap("Name:\tmysqld\nVmSwap:\t    2048 kB\n"))->toBe(2048 * 1024)
        ->and((new ProcessTable)->parseSwap("Name:\tkthreadd\n"))->toBeNull();
});

it('reads every process of a proc directory, normalizing names and reading swap only when asked', function () {
    $proc = fakeProc([
        10 => ['stat' => statLine(10, 'php-fpm8.3', 100, 50, 1000, 1000), 'cmdline' => "php-fpm: pool shop\0", 'status' => "VmSwap:\t 4 kB\n"],
        11 => ['stat' => statLine(11, 'mysqld', 10, 5, 500, 2000), 'status' => "VmSwap:\t 8 kB\n"],
        12 => ['stat' => statLine(12, 'php8.4', 20, 10, 800, 500), 'cmdline' => "/usr/bin/php8.4\0/var/www/artisan\0horizon\0"],
        13 => ['stat' => statLine(13, 'node', 5, 5, 900, 300), 'cmdline' => "/usr/bin/node\0/app/server.js\0"],
    ]);
    mkdir("{$proc}/self-not-a-pid");

    $table = new ProcessTable($proc);
    $withoutSwap = $table->read(false);
    $withSwap = $table->read(true);

    expect($withoutSwap)->toHaveCount(4)
        ->and(array_column($withoutSwap, 'name'))->toEqualCanonicalizing(['php-fpm: shop', 'mysqld', 'php artisan horizon', 'node server.js'])
        ->and(array_column($withoutSwap, 'swap_bytes'))->toBe([null, null, null, null])
        ->and(array_column($withSwap, 'swap_bytes'))->toEqualCanonicalizing([4096, 8192, null, null])
        ->and($withSwap[0]['uid'])->toBe(fileowner("{$proc}/10"))
        ->and($table->uptime())->toBe(5000.25);
});

it('gives no table when proc cannot be read', function () {
    expect((new ProcessTable('/does/not/exist'))->read(false))->toBeNull()
        ->and((new ProcessTable('/does/not/exist'))->seesEveryone())->toBeFalse();
});
