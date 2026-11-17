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

it('keeps a folder deeper down when it is large and not hidden', function (string $path, int $megabytes, bool $kept) {
    expect(siteSizes()->keepsDeep($path, '/home', $megabytes * 1024 * 1024))->toBe($kept);
})->with([
    ['/home/forge/example.nl/vendor', 400, true],
    ['/home/forge/example.nl/releases/20261117/vendor', 400, true],
    ['/home/forge/example.nl/vendor', 99, false],
    ['/home/forge/example.nl', 400, false],
    ['/home/forge/example.nl/.git', 400, false],
    ['/home/forge/.config/huge', 400, false],
    ['/homeless/forge/example.nl/vendor', 400, false],
]);

it('fills the room left with the largest folders deeper down, never pushing out the ones it always sends', function () {
    $root = siteRoot(['forge']);
    $mb = 1024;
    $lines = '';

    foreach (range(1, SiteSizes::MAX_ENTRIES - 3) as $index) {
        $lines .= "1\t{$root}/forge/site{$index}\n";
    }

    foreach ([300, 500, 200, 400, 150] as $index => $megabytes) {
        $lines .= ($megabytes * $mb)."\t{$root}/forge/site1/deep{$index}\n";
    }

    $lines .= (9000 * $mb)."\t{$root}/forge\n";

    $paths = array_column(siteSizes()->sites([$root], fakeDu($lines))['entries'], 'path');

    expect($paths)->toHaveCount(SiteSizes::MAX_ENTRIES)
        ->toContain("{$root}/forge", "{$root}/forge/site1", "{$root}/forge/site".(SiteSizes::MAX_ENTRIES - 3))
        ->and(array_values(array_filter($paths, fn (string $path): bool => str_contains($path, '/deep'))))->toBe([
            "{$root}/forge/site1/deep1",
            "{$root}/forge/site1/deep3",
        ]);
});

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

    $bytes = array_column($measured['entries'], 'bytes', 'path');

    expect($measured['complete'])->toBeTrue()
        ->and($measured['unmeasured'])->toBe([])
        ->and(array_keys($bytes))->toBe(["{$root}/forge", "{$root}/forge/example.nl", "{$root}/forge/example.nl/storage/logs"])
        ->and($bytes["{$root}/forge/example.nl"])->toBe(1200 * 1024)
        ->and($bytes["{$root}/forge/example.nl/storage/logs"])->toBe(300 * 1024)
        /* The home directory is what is in it: its one site, plus its own entry. */
        ->and($bytes["{$root}/forge"])->toBeGreaterThanOrEqual(1200 * 1024)->toBeLessThan(1300 * 1024)
        ->and(array_unique(array_column($measured['entries'], 'owner')))->toBe([(new Users)->name((int) fileowner($root))]);
});

it('loses only the folder du ran out of time on', function () {
    $root = siteRoot(['big', 'small']);

    $runner = new class extends Runner
    {
        public function run(array $command, int $timeoutSeconds, ?callable $onLine = null, string $separator = "\n"): ?array
        {
            return parent::run($command, 1, $onLine, $separator);
        }
    };

    $du = fakeDu("10\t{$root}/big/a\n20\t{$root}/small\n", slowFolder: "{$root}/big");

    $measured = (new SiteSizes($runner, new Users))->sites([$root], $du);

    expect($measured['complete'])->toBeFalse()
        ->and($measured['unmeasured'])->toBe(["{$root}/big"])
        ->and(array_column($measured['entries'], 'path'))->toBe(["{$root}/small", "{$root}/big/a"]);
});

it('keeps every other site of a home directory when one is too big to count in time', function () {
    $root = siteRoot(['kylian/.worktrees', 'kylian/shop.nl', 'kylian/blog.nl']);

    $runner = new class extends Runner
    {
        public function run(array $command, int $timeoutSeconds, ?callable $onLine = null, string $separator = "\n"): ?array
        {
            return parent::run($command, 1, $onLine, $separator);
        }
    };

    $du = fakeDu(implode("\n", [
        "4000\t{$root}/kylian/.worktrees/a",
        "500\t{$root}/kylian/shop.nl",
        "300\t{$root}/kylian/blog.nl",
    ])."\n", slowFolder: "{$root}/kylian/.worktrees");

    $measured = (new SiteSizes($runner, new Users))->sites([$root], $du);
    $bytes = array_column($measured['entries'], 'bytes', 'path');

    /* The home directory is at least its sites that finished: PingPong
       reads it as unmeasured, so as a lower bound. */
    expect($measured['complete'])->toBeFalse()
        ->and($measured['unmeasured'])->toBe(["{$root}/kylian/.worktrees", "{$root}/kylian"])
        ->and($bytes["{$root}/kylian/shop.nl"])->toBe(500 * 1024)
        ->and($bytes["{$root}/kylian/blog.nl"])->toBe(300 * 1024)
        ->and($bytes["{$root}/kylian"])->toBeGreaterThanOrEqual(800 * 1024)->toBeLessThan(4000 * 1024);
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

it('never reads a folder name as the size of another folder', function () {
    $root = siteRoot(['evil']);

    /* Folders inside folders, so the path du prints holds a line of its own. */
    mkdir("{$root}/evil/a\n999999999\t{$root}/ghost", 0755, true);

    $sites = array_column(siteSizes()->sites([$root], (new Runner)->find('du'))['entries'], 'bytes', 'path');

    expect($sites)->not->toHaveKey("{$root}/ghost")
        ->and($sites)->toHaveKey("{$root}/evil");
});
