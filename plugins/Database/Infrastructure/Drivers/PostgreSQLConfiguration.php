<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use PDO;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;

/**
 * PostgreSQLConfiguration — PostgreSQL database configuration.
 * Supports both Unix socket and TCP connections with SSL.
 */
final readonly class PostgreSQLConfiguration implements DatabaseConfigurationContract
{
    public function __construct(
        private string $host = 'localhost',
        private int $port = 5432,
        private string $database = 'postgres',
        private string $username = 'postgres',
        private string $password = '',
        private string $sslMode = 'prefer',  // disable, allow, prefer, require, verify-ca, verify-full
        private ?string $unixSocket = null,
    ) {}

    public function driver(): string
    {
        return 'pgsql';
    }

    public function dsn(): string
    {
        if ($this->unixSocket) {
            return "pgsql:host={$this->unixSocket};dbname={$this->database};sslmode={$this->sslMode}";
        }

        return "pgsql:host={$this->host};port={$this->port};dbname={$this->database};sslmode={$this->sslMode}";
    }

    public function username(): ?string
    {
        return $this->username ?: null;
    }

    public function password(): ?string
    {
        return $this->password ?: null;
    }

    public function pdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    public function initStatements(): array
    {
        return [];
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'ssl_mode' => $this->sslMode,
            'unix_socket' => $this->unixSocket,
        ];
    }
}
