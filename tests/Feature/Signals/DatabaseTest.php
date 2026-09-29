<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use KobaltDigital\PingPong\Actions\CollectSignals;
use KobaltDigital\PingPong\DatabaseProbe;
use KobaltDigital\PingPong\Signals\Database;
use KobaltDigital\PingPong\Signals\FailedJobs;

it('reports the database as reachable with its latency', function () {
    $signal = app(Database::class)->collect();

    expect($signal['reachable'])->toBeTrue()
        ->and($signal['latency_ms'])->toBeFloat()->toBeGreaterThanOrEqual(0)
        ->and($signal['error'])->toBeNull();
});

it('reports an unreachable database as a fact', function () {
    config()->set('database.connections.testing.database', '/nonexistent/pingpong.sqlite');

    DB::purge();

    $signal = app(Database::class)->collect();

    expect($signal['reachable'])->toBeFalse()
        ->and($signal['latency_ms'])->toBeNull()
        ->and($signal['error'])->toContain('pingpong.sqlite');
});

it('keeps the error short enough for PingPong to store', function () {
    config()->set('database.connections.testing.database', '/nonexistent/'.str_repeat('a', 300).'.sqlite');

    DB::purge();

    $signal = app(Database::class)->collect();

    expect($signal['reachable'])->toBeFalse()
        ->and(mb_strlen($signal['error']))->toBeLessThanOrEqual(255);
});

it('connects to a dead database once per tick, however many signals need it', function () {
    config()->set('database.default', 'mysql');
    config()->set('queue.failed.database', 'mysql');
    config()->set('pingpong-agent.signals', [Database::class, FailedJobs::class]);

    $attempts = 0;

    $deadDatabase = function (array $config) use (&$attempts) {
        return new MySqlConnection(function () use (&$attempts) {
            $attempts++;

            throw new PDOException('SQLSTATE[HY000] [2002] Connection timed out');
        }, 'app', '', $config);
    };

    app('db')->extend('mysql', $deadDatabase);
    app('db')->extend(DatabaseProbe::CONNECTION_NAME, $deadDatabase);

    expect(app(CollectSignals::class)->execute())->toBe([
        'database' => [
            'reachable' => false,
            'latency_ms' => null,
            'error' => 'SQLSTATE[HY000] [2002] Connection timed out',
        ],
        'failed_jobs' => [
            'new' => null,
            'error' => 'SQLSTATE[HY000] [2002] Connection timed out',
        ],
    ])->and($attempts)->toBe(1);
});
