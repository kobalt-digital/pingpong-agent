<?php

use Illuminate\Config\Repository;
use Illuminate\Database\MySqlConnection;
use KobaltDigital\PingPong\DatabaseProbe;

it('reports malformed database configuration without connecting', function (string $key, mixed $value) {
    $configuration = new Repository(config()->all());
    $configuration->set($key, $value);

    $probe = new DatabaseProbe(app('db'), $configuration);

    expect($probe->connection())->toBeNull()
        ->and($probe->error())->toBeString()->not->toBeEmpty()
        ->and($probe->connection())->toBeNull();
})->with([
    'name' => ['database.default', []],
    'connection' => ['database.connections.testing', 'invalid'],
]);

it('reports malformed network connection options', function () {
    $configuration = new Repository([
        'database' => [
            'default' => 'mysql',
            'connections' => ['mysql' => ['driver' => 'mysql', 'options' => 'invalid']],
        ],
    ]);

    $probe = new DatabaseProbe(app('db'), $configuration);

    expect($probe->connection())->toBeNull()
        ->and($probe->error())->toBe('Database connection options must be an array.');
});

/**
 * Stands in for a networked default connection: counts every connect and
 * either connects to an in memory database or fails like a dead host.
 */
function fakeNetworkedDatabase(bool $reachable, int &$attempts, ?array &$config = null): void
{
    config()->set('database.default', 'mysql');

    app('db')->extend(DatabaseProbe::CONNECTION_NAME, function (array $connectionConfig) use ($reachable, &$attempts, &$config) {
        $config = $connectionConfig;

        return new MySqlConnection(function () use ($reachable, &$attempts) {
            $attempts++;

            if (! $reachable) {
                throw new PDOException('SQLSTATE[HY000] [2002] Connection timed out');
            }

            return new PDO('sqlite::memory:');
        }, 'app', '', $connectionConfig);
    });
}

it('shares the default sqlite connection', function () {
    expect(app(DatabaseProbe::class)->connection())->toBe(app('db')->connection());
});

it('connects a clone of a networked default connection with a short connect timeout', function (string $driver) {
    $attempts = 0;

    fakeNetworkedDatabase(reachable: true, attempts: $attempts, config: $config);

    config()->set('database.connections.mysql.driver', $driver);

    $connection = app(DatabaseProbe::class)->connection();

    expect($connection)->toBeInstanceOf(MySqlConnection::class)
        ->and($attempts)->toBe(1)
        ->and($config['driver'])->toBe($driver)
        ->and($config['options'][PDO::ATTR_TIMEOUT])->toBe(DatabaseProbe::CONNECT_TIMEOUT_SECONDS)
        ->and(app(DatabaseProbe::class)->connection())->toBe($connection)
        ->and($attempts)->toBe(1);
})->with(['mysql', 'mariadb', 'pgsql']);

it('bounds a sql server connect with its login timeout', function () {
    $attempts = 0;

    fakeNetworkedDatabase(reachable: true, attempts: $attempts, config: $config);

    config()->set('database.connections.mysql.driver', 'sqlsrv');

    app(DatabaseProbe::class)->connection();

    expect($config['login_timeout'])->toBe(DatabaseProbe::CONNECT_TIMEOUT_SECONDS);
});

it('tries a dead database once per process', function () {
    $attempts = 0;

    fakeNetworkedDatabase(reachable: false, attempts: $attempts);

    expect(app(DatabaseProbe::class)->connection())->toBeNull()
        ->and(app(DatabaseProbe::class)->connection())->toBeNull()
        ->and($attempts)->toBe(1);
});

it('hands out another connection as configured, without probing the default', function () {
    $attempts = 0;

    fakeNetworkedDatabase(reachable: false, attempts: $attempts);

    expect(app(DatabaseProbe::class)->connectionFor('testing'))->toBe(app('db')->connection('testing'))
        ->and($attempts)->toBe(0)
        ->and(app(DatabaseProbe::class)->connectionFor('mysql'))->toBeNull()
        ->and(app(DatabaseProbe::class)->connectionFor(null))->toBeNull()
        ->and($attempts)->toBe(1);
});
