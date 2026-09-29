<?php

namespace KobaltDigital\PingPong;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use PDO;
use Throwable;

/**
 * Opens the one database connection the collectors share. It is a clone of
 * the default connection with a short connect timeout, so a database host
 * that drops packets delays the tick by seconds, not minutes. The outcome is
 * kept for the rest of the process, so a dead database is waited on once.
 */
class DatabaseProbe
{
    public const CONNECTION_NAME = 'pingpong-agent';

    public const CONNECT_TIMEOUT_SECONDS = 2;

    private bool $probed = false;

    private ?Connection $connection = null;

    private ?string $error = null;

    public function __construct(
        private DatabaseManager $database,
        private Repository $config,
    ) {}

    public function connection(): ?Connection
    {
        if ($this->probed) {
            return $this->connection;
        }

        $this->probed = true;

        try {
            $connection = $this->database->connection($this->register());

            // getPdo() throws on a failed connect, where a query would treat
            // it as a lost connection and wait for the timeout a second time.
            $connection->getPdo();
        } catch (Throwable $exception) {
            $this->error = $exception->getMessage();

            return null;
        }

        return $this->connection = $connection;
    }

    /**
     * The default connection is read through the probe's, which connects
     * with a short timeout and is tried once per process, so null means the
     * default database is unreachable. Another connection is used as
     * configured.
     */
    public function connectionFor(?string $connectionName): ?Connection
    {
        if ($connectionName !== null && $connectionName !== $this->config->get('database.default')) {
            return $this->database->connection($connectionName);
        }

        return $this->connection();
    }

    /**
     * Why connection() answered null, so the signals can report it without
     * connecting again.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * SQLite has no network connect to bound, and an in memory database only
     * exists on its own connection, so SQLite keeps the default connection.
     */
    private function register(): string
    {
        $defaultName = $this->config->get('database.default');
        $default = $this->config->get("database.connections.{$defaultName}");

        if (($default['driver'] ?? null) === 'sqlite') {
            return $defaultName;
        }

        $this->config->set('database.connections.'.self::CONNECTION_NAME, $this->withConnectTimeout($default));

        return self::CONNECTION_NAME;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, mixed>
     */
    private function withConnectTimeout(array $connection): array
    {
        if (($connection['driver'] ?? null) === 'sqlsrv') {
            $connection['login_timeout'] = self::CONNECT_TIMEOUT_SECONDS;

            return $connection;
        }

        // pdo_mysql and pdo_pgsql both use this as their connect timeout.
        $connection['options'] ??= [];
        $connection['options'][PDO::ATTR_TIMEOUT] = self::CONNECT_TIMEOUT_SECONDS;

        return $connection;
    }
}
