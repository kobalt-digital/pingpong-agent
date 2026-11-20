<?php

use KobaltDigital\PingPong\Machine\Client;
use KobaltDigital\PingPong\Machine\Config;

/**
 * A PingPong that writes down the request it got and answers with the
 * status the path names: `/201` answers 201.
 *
 * @return array{url: string, requests: string, stop: Closure}
 */
function fakePingPong(): array
{
    $directory = sys_get_temp_dir().'/pingpong-fake-'.bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents("{$directory}/router.php", <<<'PHP'
    <?php
    file_put_contents(__DIR__.'/requests.log', json_encode([
        'uri' => $_SERVER['REQUEST_URI'],
        'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        'body' => json_decode(file_get_contents('php://input'), true),
    ])."\n", FILE_APPEND);
    if ($_SERVER['REQUEST_URI'] === '/redirect') {
        header('Location: https://elsewhere.test/');
        http_response_code(302);
        return;
    }
    http_response_code((int) ltrim($_SERVER['REQUEST_URI'], '/') ?: 200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'recorded']);
    PHP);

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$directory}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    foreach (range(1, 50) as $attempt) {
        if (@fsockopen('127.0.0.1', $port) !== false) {
            break;
        }

        usleep(100_000);
    }

    return [
        'url' => "http://127.0.0.1:{$port}",
        'requests' => "{$directory}/requests.log",
        'stop' => function () use ($process): void {
            proc_terminate($process);
            proc_close($process);
        },
    ];
}

it('posts JSON with the machine key and reads the answer', function (bool $useCurl) {
    $pingPong = fakePingPong();

    try {
        $client = new Client(new Config($pingPong['url'], 'pp_machine_secret', sys_get_temp_dir()), 'pingpong-machine/test', $useCurl);

        $answer = $client->post('201', ['server' => 'web-1']);
        $request = json_decode(trim(file_get_contents($pingPong['requests'])), true);

        expect($answer)->toBe(['status' => 201, 'body' => ['status' => 'recorded']])
            ->and($request)->toBe(['uri' => '/201', 'authorization' => 'Bearer pp_machine_secret', 'body' => ['server' => 'web-1']])
            ->and($client->post('422', [])['status'])->toBe(422);
    } finally {
        ($pingPong['stop'])();
    }
})->with([
    'curl' => [true],
    'stream' => [false],
]);

it('never follows a redirect with the key', function (bool $useCurl) {
    $pingPong = fakePingPong();

    try {
        $client = new Client(new Config($pingPong['url'], 'pp_machine_secret', sys_get_temp_dir()), 'pingpong-machine/test', $useCurl);

        expect($client->post('redirect', [])['status'])->toBe(302);
    } finally {
        ($pingPong['stop'])();
    }
})->with([
    'curl' => [true],
    'stream' => [false],
]);

it('gives nothing when PingPong cannot be reached, and says why', function (bool $useCurl, string $error) {
    $client = new Client(new Config('http://127.0.0.1:1', 'k', sys_get_temp_dir()), 'pingpong-machine/test', $useCurl);

    expect($client->post('api/machine/tick', []))->toBeNull()
        ->and($client->lastError)->toBe($error);
})->with([
    'curl' => [true, 'connect'],
    'stream' => [false, 'other'],
]);
