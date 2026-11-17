<?php

use KobaltDigital\PingPong\Machine\Updater;

/*
 * The release is a folder here: the phar the newest release would carry and
 * its checksum, read over file:// as the agent reads them over https.
 */

/**
 * @return array{release: string, installed: string, url: string}
 */
function releaseOf(string $newPhar): array
{
    $directory = sys_get_temp_dir().'/pingpong-release-'.bin2hex(random_bytes(4));
    mkdir("{$directory}/release", 0755, true);
    mkdir("{$directory}/installed", 0755, true);

    file_put_contents("{$directory}/release/pingpong-machine.phar", $newPhar);
    file_put_contents("{$directory}/release/pingpong-machine.phar.sha256", hash('sha256', $newPhar)."  pingpong-machine.phar\n");
    file_put_contents("{$directory}/installed/pingpong-machine.phar", "<?php echo \"1.0.0\\n\";\n");

    return [
        'release' => "{$directory}/release",
        'installed' => "{$directory}/installed/pingpong-machine.phar",
        'url' => "file://{$directory}/release/pingpong-machine.phar",
    ];
}

it('takes the phar of the newest release once it answers with its version', function () {
    $release = releaseOf("<?php echo \"1.1.0\\n\";\n");

    expect((new Updater($release['url']))->update($release['installed']))->toBe('updated')
        ->and(file_get_contents($release['installed']))->toBe("<?php echo \"1.1.0\\n\";\n")
        ->and(glob(dirname($release['installed']).'/*'))->toBe([$release['installed']]);
});

it('keeps the phar it has when the new one does not run', function (string $broken, string $problem) {
    $release = releaseOf($broken);

    expect((new Updater($release['url']))->update($release['installed']))->toBe("The new phar does not run, so the old one stays: {$problem}")
        ->and(file_get_contents($release['installed']))->toBe("<?php echo \"1.0.0\\n\";\n")
        ->and(glob(dirname($release['installed']).'/*'))->toBe([$release['installed']]);
})->with([
    'a fatal error' => ["<?php exit(255);\n", 'it exited with 255.'],
    'no version' => ["<?php echo \"Usage: nothing\\n\";\n", 'it did not name its version.'],
]);

it('keeps the phar it has when the download does not match its checksum', function () {
    $release = releaseOf("<?php echo \"1.1.0\\n\";\n");
    file_put_contents("{$release['release']}/pingpong-machine.phar", "<?php echo \"6.6.6\\n\";\n");

    expect((new Updater($release['url']))->update($release['installed']))->toBe('The downloaded phar does not match its checksum.')
        ->and(file_get_contents($release['installed']))->toBe("<?php echo \"1.0.0\\n\";\n");
});

it('leaves the phar alone when the release holds the same one, and when there is no release to read', function () {
    $release = releaseOf("<?php echo \"1.0.0\\n\";\n");

    expect((new Updater($release['url']))->update($release['installed']))->toBe('current')
        ->and((new Updater('file:///nowhere/pingpong-machine.phar'))->update($release['installed']))->toBe('Could not read the checksum of the new phar.');
});

it('takes the machine agent from a release, never from a branch', function () {
    expect(Updater::RELEASE_URL)->toStartWith('https://github.com/kobalt-digital/pingpong-agent/releases/latest/download/')
        ->and(file_get_contents(dirname(__DIR__, 3).'/machine/install.sh'))->toContain('https://github.com/kobalt-digital/pingpong-agent/releases/latest/download}')
        ->not->toContain('/main/');
});

it('checks a phar of its own, and leaves the one an update running beside it is checking alone', function () {
    $release = releaseOf("<?php echo \"1.1.0\\n\";\n");
    $beside = dirname($release['installed']).'/pingpong-machine.0123456789ab.new.phar';
    file_put_contents($beside, 'another update is checking this');

    expect((new Updater($release['url']))->update($release['installed']))->toBe('updated')
        ->and(file_get_contents($beside))->toBe('another update is checking this')
        ->and(glob(dirname($release['installed']).'/*'))->toHaveCount(2);
});
