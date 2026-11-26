<?php

use KobaltDigital\PingPong\Machine\AptHistory;

function aptHistoryDirectory(): string
{
    $directory = sys_get_temp_dir().'/pingpong-apt-'.bin2hex(random_bytes(4));
    mkdir($directory);

    return $directory;
}

it('names unattended upgrades, the user who asked through sudo, and nobody where apt names nobody', function () {
    $directory = aptHistoryDirectory();
    file_put_contents("{$directory}/history.log", <<<'TXT'

        Start-Date: 2026-11-26  06:12:03
        Commandline: /usr/bin/unattended-upgrade
        Upgrade: openssl:amd64 (3.0.13-0ubuntu3.4, 3.0.13-0ubuntu3.5), libssl3t64:amd64 (3.0.13-0ubuntu3.4, 3.0.13-0ubuntu3.5)
        End-Date: 2026-11-26  06:12:07

        Start-Date: 2026-11-26  09:30:00
        Commandline: apt install nginx
        Requested-By: kylian (1000)
        Install: nginx:amd64 (1.24.0-2ubuntu7)
        End-Date: 2026-11-26  09:30:12

        Start-Date: 2026-11-26  09:45:00
        Commandline: apt install unattended-upgrades
        Requested-By: forge (1000)
        Install: unattended-upgrades:amd64 (2.9.1+nmu4ubuntu1)
        End-Date: 2026-11-26  09:45:10

        Start-Date: 2026-11-26  10:00:00
        Commandline: apt upgrade
        Upgrade: curl:amd64 (8.5.0-2, 8.5.0-3)
        End-Date: 2026-11-26  10:00:05

        TXT);

    expect((new AptHistory("{$directory}/history.log"))->who([
        '2026-11-26 06:12:04',
        '2026-11-26 09:30:05',
        '2026-11-26 09:45:05',
        '2026-11-26 10:00:01',
        '2026-11-26 11:00:00',
        '2026-11-25 23:00:00',
    ]))->toBe(['unattended-upgrade', 'kylian', 'forge', null, null, null]);
});

it('counts a run apt has not finished writing', function () {
    $directory = aptHistoryDirectory();
    file_put_contents("{$directory}/history.log", "Start-Date: 2026-11-26  06:12:03\nCommandline: /usr/bin/unattended-upgrade\n");

    expect((new AptHistory("{$directory}/history.log"))->who(['2026-11-26 06:12:04']))->toBe(['unattended-upgrade']);
});

it('reads a run from before the rotation from the gzipped history', function () {
    $directory = aptHistoryDirectory();
    file_put_contents("{$directory}/history.log.1.gz", gzencode("Start-Date: 2026-11-30  23:58:00\nCommandline: apt upgrade\nRequested-By: kylian (1000)\nEnd-Date: 2026-11-30  23:59:30\n"));
    file_put_contents("{$directory}/history.log", "Start-Date: 2026-12-01  00:01:00\nCommandline: /usr/bin/unattended-upgrade\nEnd-Date: 2026-12-01  00:01:05\n");

    expect((new AptHistory("{$directory}/history.log"))->who(['2026-11-30 23:59:00', '2026-12-01 00:01:01']))
        ->toBe(['kylian', 'unattended-upgrade']);
});

it('knows nobody where apt keeps no history', function () {
    expect((new AptHistory('/does/not/exist/history.log'))->who(['2026-11-26 06:12:04']))->toBe([null]);
});
