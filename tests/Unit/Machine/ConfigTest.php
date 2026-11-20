<?php

use KobaltDigital\PingPong\Machine\Config;

it('reads the url, key and state directory', function () {
    $config = Config::parse('{"url": "https://pingpong.example/", "key": "pp_machine_abc", "state_dir": "/var/lib/x/"}', '/default');

    expect($config->url)->toBe('https://pingpong.example')
        ->and($config->key)->toBe('pp_machine_abc')
        ->and($config->stateDirectory)->toBe('/var/lib/x');
});

it('keeps the state in the default directory when the file names none', function () {
    expect(Config::parse('{"url": "https://pingpong.example", "key": "k"}', '/default')->stateDirectory)->toBe('/default');
});

it('refuses a file without a url or key', function (string $json) {
    Config::parse($json, '/default');
})->with([
    'not json' => ['nope'],
    'no key' => ['{"url": "https://pingpong.example"}'],
    'no url' => ['{"key": "k"}'],
    'not a url' => ['{"url": "ftp://x", "key": "k"}'],
])->throws(RuntimeException::class);

it('takes the mode from the file', function () {
    $config = Config::parse('{"url": "https://pingpong.example", "key": "k", "mode": "system"}', '/default');

    expect($config->system)->toBeTrue()
        ->and(Config::parse('{"url": "https://pingpong.example", "key": "k", "mode": "user"}', '/default', system: true)->system)->toBeFalse()
        ->and(Config::parse('{"url": "https://pingpong.example", "key": "k"}', '/default', system: true)->system)->toBeTrue();
});

it('never sends the key in the clear, except to this machine', function (string $url, bool $accepted) {
    $parse = fn () => Config::parse(json_encode(['url' => $url, 'key' => 'k']), '/default');

    $accepted ? expect($parse()->url)->toBe($url) : expect($parse)->toThrow(RuntimeException::class);
})->with([
    'https' => ['https://pingpong.example', true],
    'http elsewhere' => ['http://pingpong.example', false],
    'http to this machine' => ['http://127.0.0.1:8000', true],
    'http to localhost' => ['http://localhost', true],
]);
