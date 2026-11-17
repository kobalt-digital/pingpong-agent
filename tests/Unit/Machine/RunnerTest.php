<?php

use KobaltDigital\PingPong\Machine\Runner;

it('gives the exit code and the output of a program that ends', function () {
    expect((new Runner)->run([PHP_BINARY, '-r', 'echo "1.2.3\n"; exit(3);'], 5))
        ->toBe(['exit' => 3, 'output' => "1.2.3\n", 'timed_out' => false]);
});

it('gives up on a program at the deadline, also one that closed its output and went on', function (string $code) {
    $started = microtime(true);
    $result = (new Runner)->run([PHP_BINARY, '-r', $code], 1);

    expect($result['timed_out'] ?? null)->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(4.0);
})->with([
    'a program that hangs' => ['sleep(30);'],
    'one that closes its output first' => ['fclose(STDOUT); sleep(30);'],
]);
