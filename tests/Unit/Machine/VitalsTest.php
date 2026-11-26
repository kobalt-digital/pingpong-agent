<?php

use KobaltDigital\PingPong\Machine\Runner;
use KobaltDigital\PingPong\Machine\State;
use KobaltDigital\PingPong\Machine\Vitals;
use KobaltDigital\PingPong\Tests\Fakes\FakeHost;

/** Answers `apt list --upgradable` with the given output, and counts the calls. */
class FakeAptRunner extends Runner
{
    public int $asked = 0;

    public function __construct(private string $output) {}

    public function find(string $name): ?string
    {
        return $name === 'apt' ? '/usr/bin/apt' : null;
    }

    public function run(array $command, int $timeoutSeconds, ?callable $onLine = null, string $separator = "\n"): ?array
    {
        $this->asked++;

        return ['exit' => 0, 'output' => $this->output, 'timed_out' => false];
    }
}

/** A Debian machine: no update-notifier, and apt with or without its lists. */
function debianHost(bool $hasAptLists = true): FakeHost
{
    return new class($hasAptLists) extends FakeHost
    {
        public function __construct(private bool $lists)
        {
            parent::__construct(osName: 'Debian GNU/Linux 13 (trixie)', rebootRequired: false, rebootPackages: [], updates: null, updatesStamp: 1763328600);
        }

        public function hasAptLists(): bool
        {
            return $this->lists;
        }
    };
}

it('counts the updates with apt where update-notifier does not run, and asks apt once until they change', function () {
    $runner = new FakeAptRunner(<<<'TXT'
        Listing...
        libssl3t64/stable-security 3.5.1-1+deb13u1 amd64 [upgradable from: 3.5.1-1]
        nginx/stable 1.26.3-3+deb13u1 amd64 [upgradable from: 1.26.3-3]
        TXT);
    $vitals = new Vitals(debianHost(), $runner);
    $state = new State(sys_get_temp_dir().'/pingpong-machine-'.bin2hex(random_bytes(4)));

    $host = $vitals->host($state);
    $vitals->host($state);

    expect($host)->toMatchArray([
        'updates_pending' => 2,
        'updates_security' => 1,
        'update_packages' => [
            ['name' => 'libssl3t64', 'version' => '3.5.1-1+deb13u1', 'security' => true],
            ['name' => 'nginx', 'version' => '1.26.3-3+deb13u1', 'security' => false],
        ],
    ])->and($runner->asked)->toBe(1);
});

it('says a Debian machine is up to date when apt finds nothing to upgrade', function () {
    $host = (new Vitals(debianHost(), new FakeAptRunner("Listing...\n")))->host(new State(sys_get_temp_dir().'/pingpong-machine-'.bin2hex(random_bytes(4))));

    expect($host)->toMatchArray(['updates_pending' => 0, 'updates_security' => 0, 'update_packages' => null]);
});

it('counts nothing where apt has no lists to compare against, as in a container image', function () {
    $runner = new FakeAptRunner("Listing...\n");

    $host = (new Vitals(debianHost(hasAptLists: false), $runner))->host(new State(sys_get_temp_dir().'/pingpong-machine-'.bin2hex(random_bytes(4))));

    expect($host)->toMatchArray(['updates_pending' => null, 'updates_security' => null, 'update_packages' => null])
        ->and($runner->asked)->toBe(0);
});

/** Answers `df -iP` with the given output, and keeps what it was asked. */
class FakeDfRunner extends Runner
{
    /** @var list<list<string>> */
    public array $commands = [];

    public function __construct(private string $output) {}

    public function find(string $name): ?string
    {
        return $name === 'df' ? '/bin/df' : null;
    }

    public function run(array $command, int $timeoutSeconds, ?callable $onLine = null, string $separator = "\n"): ?array
    {
        $this->commands[] = $command;

        return ['exit' => 0, 'output' => $this->output, 'timed_out' => false];
    }
}

function mountsFile(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'mounts');
    file_put_contents($file, $contents);

    return $file;
}

it('reports every local disk once, by its shortest mount point', function () {
    $data = sys_get_temp_dir().'/pingpong-data disk-'.bin2hex(random_bytes(4));
    mkdir($data);
    $escaped = str_replace(' ', '\\040', $data);

    $mounts = mountsFile(implode("\n", [
        '/dev/sda1 / ext4 rw,relatime 0 0',
        '/dev/sda1 /srv/bound ext4 rw,relatime 0 0',
        'tmpfs /run tmpfs rw,nosuid 0 0',
        'overlay /var/lib/docker/overlay2/x/merged overlay rw 0 0',
        '/dev/loop3 /snap/core/1 squashfs ro 0 0',
        'nas:/backups /mnt/backups nfs4 rw 0 0',
        "/dev/sdb1 {$escaped} xfs rw 0 0",
        '/dev/sdc1 /does/not/exist ext4 rw 0 0',
    ]));
    $runner = new FakeDfRunner(implode("\n", [
        'Filesystem      Inodes  IUsed   IFree IUse% Mounted on',
        '/dev/sda1      6553600 400000 6153600    7% /',
        "/dev/sdb1       1000000    10  999990    1% {$data}",
    ]));

    $disks = (new Vitals(new FakeHost, $runner, mounts: $mounts))->disks();

    expect(array_column($disks, 'mount'))->toBe(['/', $data])
        ->and(array_column($disks, 'type'))->toBe(['ext4', 'xfs'])
        ->and($disks[0]['inodes_total'])->toBe(6553600)
        ->and($disks[1]['inodes_free'])->toBe(999990)
        ->and($disks[0]['total_bytes'])->toBeGreaterThan(0)
        ->and($runner->commands)->toBe([['/bin/df', '-iP', '/', '/does/not/exist', $data]]);
});

it('reports no disks where none is local', function () {
    $mounts = mountsFile("tmpfs /run tmpfs rw 0 0\nnas:/x /mnt nfs rw 0 0\n");

    expect((new Vitals(new FakeHost, new FakeDfRunner(''), mounts: $mounts))->disks())->toBeNull();
});

it('leaves out the inodes of a filesystem that keeps no count', function () {
    expect((new Vitals(new FakeHost, new Runner))->parseInodesByMount(implode("\n", [
        'Filesystem Inodes IUsed IFree IUse% Mounted on',
        '/dev/sda2       0     0     0     - /data',
        '/dev/sda1     100    40    60   40% /',
    ])))->toBe(['/' => ['total' => 100, 'free' => 60]]);
});

it('sends the id of this boot with the host facts', function () {
    $bootId = tempnam(sys_get_temp_dir(), 'pingpong-boot-id-');
    file_put_contents($bootId, "E2308F53-2CFE-4B1E-BB9E-8D4BEEC6F7E3\n");

    $vitals = new Vitals(debianHost(hasAptLists: false), new FakeAptRunner(''), bootIdFile: $bootId);

    expect($vitals->host(new State(sys_get_temp_dir().'/pingpong-machine-'.bin2hex(random_bytes(4))))['boot_id'])
        ->toBe('e2308f53-2cfe-4b1e-bb9e-8d4beec6f7e3');
});

it('sends no boot id where the kernel has none to read', function (string $contents) {
    $bootId = tempnam(sys_get_temp_dir(), 'pingpong-boot-id-');
    file_put_contents($bootId, $contents);

    expect((new Vitals(new FakeHost, new Runner, bootIdFile: $bootId))->bootId())->toBeNull()
        ->and((new Vitals(new FakeHost, new Runner, bootIdFile: '/does/not/exist'))->bootId())->toBeNull();
})->with([
    'empty' => [''],
    'not an id' => ["not a boot id\n"],
]);
