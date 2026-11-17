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
