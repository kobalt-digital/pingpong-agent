<?php

use KobaltDigital\PingPong\Machine\Agent;

/*
 * The phar install.sh and self-update download is the one committed in
 * machine/dist, so it has to hold the code as it is now. Rebuild it with
 * `php -d phar.readonly=0 machine/build.php`.
 */

$root = dirname(__DIR__, 3);
$phar = "{$root}/machine/dist/pingpong-machine.phar";

it('holds the current machine agent', function () use ($root, $phar) {
    $sources = ['src/Host.php' => "{$root}/src/Host.php"];

    foreach (glob("{$root}/machine/src/*.php") as $file) {
        $sources['machine/'.basename($file)] = $file;
    }

    $archive = new Phar($phar);

    foreach ($sources as $name => $file) {
        expect(isset($archive[$name]))->toBeTrue("{$name} is missing from the phar; rebuild it.")
            ->and(file_get_contents($archive[$name]->getPathname()))->toBe(file_get_contents($file), "{$name} in the phar is out of date; rebuild it.");
    }

    expect(trim(explode(' ', file_get_contents("{$phar}.sha256"))[0]))->toBe(hash_file('sha256', $phar));
});

it('runs on its own, without Composer', function () use ($phar) {
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($phar).' version', $output, $exit);

    expect($exit)->toBe(0)
        ->and($output)->toBe([Agent::VERSION]);
});
