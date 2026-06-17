<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use PDO;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;

/**
 * SqlServerConfiguration — SQL Server database configuration.
 * Supports named pipes, TCP/IP, and shared memory protocols.
 */
final readonly class SqlServerConfiguration implements DatabaseConfigurationContract
{
    public function __construct(
        private string $server = 'localhost',
        private int $port = 1433,
        private string $database = '',
        private string $username = 'sa',
        private string $password = '',
        private bool $trustServerCertificate = false,
        private bool $encrypt = false,
    ) {}

    public function driver(): string
    {
        return 'sqlsrv';
    }

    public function dsn(): string
    {
        return "sqlsrv:Server={$this->server},{$this->port};Database={$this->database}";
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
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($this->trustServerCertificate) {
            $options['TrustServerCertificate'] = true;
        }

        if ($this->encrypt) {
            $options['Encrypt'] = true;
        }

        return $options;
    }

    public function initStatements(): array
    {
        // XACT_ABORT guarantees the whole transaction is rolled back on any
        // run-time error — matching the fail-fast semantics of the other drivers.
        return [
            'SET XACT_ABORT ON',
        ];
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'server' => $this->server,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'trust_server_certificate' => $this->trustServerCertificate,
            'encrypt' => $this->encrypt,
        ];
    }
}
