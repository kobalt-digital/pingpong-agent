<?php

use KobaltDigital\PingPong\Machine\Config;

it('reads the url, key and state directory', function () {
    $config = Config::parse('{"url": "https://pingpong.example/", "key": "pp_machine_abc", "state_dir": "/var/lib/x/"}', '/default');

    expect($config->url)->toBe('https://pingpong.example')
        ->and($config->key)->toBe('pp_machine_abc')
        ->and($config->stateDirectory)->toBe('/var/lib/x')
        ->and($config->du)->toBeNull();
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

it('takes the mode and the roots of the sites from the file', function () {
    $config = Config::parse('{"url": "https://pingpong.example", "key": "k", "mode": "system", "roots": ["/srv/sites", "relative", 3]}', '/default');

    expect($config->system)->toBeTrue()
        ->and($config->roots)->toBe(['/srv/sites'])
        ->and(Config::parse('{"url": "https://pingpong.example", "key": "k", "mode": "user"}', '/default', system: true)->system)->toBeFalse()
        ->and(Config::parse('{"url": "https://pingpong.example", "key": "k"}', '/default', system: true)->system)->toBeTrue();
});
