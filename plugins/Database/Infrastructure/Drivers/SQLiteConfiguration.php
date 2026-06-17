<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use PDO;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;

/**
 * SQLiteConfiguration — SQLite database configuration.
 * Supports file-based and in-memory databases.
 */
final readonly class SQLiteConfiguration implements DatabaseConfigurationContract
{
    public function __construct(
        private string $path = ':memory:',  // ':memory:' or file path
        private int $flags = SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
    ) {}

    public function driver(): string
    {
        return 'sqlite';
    }

    public function dsn(): string
    {
        return "sqlite:{$this->path}";
    }

    public function username(): ?string
    {
        return null;
    }

    public function password(): ?string
    {
        return null;
    }

    public function pdoOptions(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::SQLITE_ATTR_OPEN_FLAGS => $this->flags,
        ];
    }

    public function initStatements(): array
    {
        // SQLite disables foreign-key enforcement by default — enforce it, and
        // set a busy timeout so concurrent writers wait rather than fail instantly.
        $statements = [
            'PRAGMA foreign_keys = ON',
            'PRAGMA busy_timeout = 5000',
        ];

        // WAL improves read/write concurrency but is meaningless for :memory:.
        if ($this->path !== ':memory:') {
            $statements[] = 'PRAGMA journal_mode = WAL';
        }

        return $statements;
    }

    public function toArray(): array
    {
        return [
            'driver' => $this->driver(),
            'path' => $this->path,
            'in_memory' => $this->path === ':memory:',
        ];
    }
}
