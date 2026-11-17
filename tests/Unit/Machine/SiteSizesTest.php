<?php

use KobaltDigital\PingPong\Machine\Runner;
use KobaltDigital\PingPong\Machine\SiteSizes;
use KobaltDigital\PingPong\Machine\Users;

function siteSizes(): SiteSizes
{
    return new SiteSizes(new Runner, new Users);
}

it('keeps the first two levels under a root and the folders that fill up', function (string $path, bool $kept) {
    expect(siteSizes()->keeps($path, '/home'))->toBe($kept);
})->with([
    ['/home', false],
    ['/home/forge', true],
    ['/home/forge/example.nl', true],
    ['/home/forge/example.nl/vendor', false],
    ['/home/forge/example.nl/storage/logs', true],
    ['/home/forge/example.nl/storage/framework', false],
    ['/home/forge/example.nl/releases', true],
    ['/home/forge/blog.nl/public/wp-content/uploads', true],
    ['/home/forge/example.nl/releases/20261117/storage/app', true],
    ['/homeless/forge', false],
]);

it('reads a line of du', function () {
    expect(siteSizes()->parseLine("2048\t/home/forge/my site.nl"))->toBe(['path' => '/home/forge/my site.nl', 'bytes' => 2048 * 1024])
        ->and(siteSizes()->parseLine('du: cannot read directory'))->toBeNull();
});

it('names a database by its folder, without the ones MySQL keeps for itself', function (string $folder, ?string $name) {
    expect(siteSizes()->databaseName($folder))->toBe($name);
})->with([
    ['shop', 'shop'],
    ['my@002dshop', 'my-shop'],
    ['mysql', null],
    ['performance_schema', null],
    ['sys', null],
    ['#innodb_redo', null],
]);

it('measures the sites under a root, largest first, with their owner', function () {
    $root = siteRoot(['forge/example.nl/storage/logs']);

    $du = fakeDu(implode("\n", [
        "40\t{$root}/forge/example.nl/vendor/laravel",
        "800\t{$root}/forge/example.nl/vendor",
        "300\t{$root}/forge/example.nl/storage/logs",
        "1200\t{$root}/forge/example.nl",
        "1300\t{$root}/forge",
    ])."\n");

    $measured = siteSizes()->sites([$root], $du);

    expect($measured['complete'])->toBeTrue()
        ->and($measured['unmeasured'])->toBe([])
        ->and(array_column($measured['entries'], 'bytes', 'path'))->toBe([
            "{$root}/forge" => 1300 * 1024,
            "{$root}/forge/example.nl" => 1200 * 1024,
            "{$root}/forge/example.nl/storage/logs" => 300 * 1024,
        ])
        ->and(array_unique(array_column($measured['entries'], 'owner')))->toBe([(new Users)->name((int) fileowner($root))]);
});

it('loses only the folder du ran out of time on', function () {
    $root = siteRoot(['big', 'small']);

    $runner = new class extends Runner
    {
        public function run(array $command, int $timeoutSeconds, ?callable $onLine = null): ?array
        {
            return parent::run($command, 1, $onLine);
        }
    };

    $du = fakeDu("10\t{$root}/big/a\n20\t{$root}/small\n", slowFolder: "{$root}/big");

    $measured = (new SiteSizes($runner, new Users))->sites([$root], $du);

    expect($measured['complete'])->toBeFalse()
        ->and($measured['unmeasured'])->toBe(["{$root}/big"])
        ->and(array_column($measured['entries'], 'path'))->toBe(["{$root}/small", "{$root}/big/a"]);
});

it('leaves the folders it has no time left for unmeasured', function () {
    $root = siteRoot(['a', 'b']);

    $measured = siteSizes()->sites([$root], fakeDu("1\t{$root}/a\n"), budgetSeconds: 0);

    expect($measured['entries'])->toBe([])
        ->and($measured['unmeasured'])->toBe(["{$root}/a", "{$root}/b"]);
});

it('stays out of network and FUSE filesystems', function () {
    $mounts = tempnam(sys_get_temp_dir(), 'mounts');
    file_put_contents($mounts, implode("\n", [
        '/dev/vda1 / ext4 rw,relatime 0 0',
        'nas:/export /home/shared nfs4 rw 0 0',
        'sshfs#me@host: /home/forge/remote\\040disk fuse.sshfs rw 0 0',
        '/dev/vdb1 /home/shared/local ext4 rw 0 0',
    ])."\n");

    $sizes = new SiteSizes(new Runner, new Users, $mounts);

    expect($sizes->isRemote('/home/forge'))->toBeFalse()
        ->and($sizes->isRemote('/home/shared'))->toBeTrue()
        ->and($sizes->isRemote('/home/shared/site'))->toBeTrue()
        ->and($sizes->isRemote('/home/sharedother'))->toBeFalse()
        ->and($sizes->isRemote('/home/forge/remote disk'))->toBeTrue()
        ->and($sizes->isRemote('/home/shared/local'))->toBeFalse();
});

it('sends at most 400 folders', function () {
    $root = siteRoot(['users']);
    $lines = '';

    foreach (range(1, 500) as $index) {
        $lines .= "{$index}\t{$root}/users/{$index}\n";
    }

    expect(siteSizes()->sites([$root], fakeDu($lines))['entries'])->toHaveCount(SiteSizes::MAX_ENTRIES);
});

it('measures every database in the data directory of MySQL', function () {
    $directory = sys_get_temp_dir().'/pingpong-mysql-'.bin2hex(random_bytes(4));
    mkdir($directory);

    $du = fakeDu(implode("\n", [
        "100\t{$directory}/mysql",
        "50\t{$directory}/#innodb_redo",
        "900\t{$directory}/shop",
        "300\t{$directory}/my@002dblog",
        "2000\t{$directory}",
    ])."\n");

    expect(siteSizes()->databases($du, $directory))->toBe([
        ['name' => 'shop', 'bytes' => 900 * 1024],
        ['name' => 'my-blog', 'bytes' => 300 * 1024],
    ])->and(siteSizes()->databases($du, '/does/not/exist'))->toBeNull();
});
