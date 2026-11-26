<?php

use KobaltDigital\PingPong\Machine\DebianVersion;

it('orders versions the way dpkg does', function (string $older, string $newer) {
    expect(DebianVersion::compare($older, $newer))->toBeLessThan(0)
        ->and(DebianVersion::compare($newer, $older))->toBeGreaterThan(0);
})->with([
    'a revision' => ['3.0.13-0ubuntu3.4', '3.0.13-0ubuntu3.5'],
    'a security update' => ['1.26.3-3', '1.26.3-3+deb13u1'],
    'a tilde before the release' => ['1.0~rc1', '1.0'],
    'a double tilde' => ['1.0~~', '1.0~'],
    'an epoch over the upstream version' => ['2.50-1', '1:2.43-1'],
    'numbers by value' => ['1.9', '1.010'],
    'letters before other characters' => ['1.2.3-A', '1.2.3-a'],
    'an added revision' => ['1.0-1', '1.0-1ubuntu1'],
]);

it('holds versions that differ in leading zeros only as equal', function () {
    expect(DebianVersion::compare('0.9', '00.9'))->toBe(0);
});
