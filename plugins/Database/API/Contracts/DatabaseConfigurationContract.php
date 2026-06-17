<?php

declare(strict_types=1);

namespace Plugins\Database\API\Contracts;

/**
 * DatabaseConfigurationContract — defines how database connections are configured.
 * Implementations support specific drivers (MySQL, PostgreSQL, SQLite, SQL Server).
 */
interface DatabaseConfigurationContract
{
    /**
     * Get the database driver name.
     */
    public function driver(): string;

    /**
     * Get the DSN (Data Source Name) for PDO.
     */
    public function dsn(): string;

    /**
     * Get the username for authentication.
     */
    public function username(): ?string;

    /**
     * Get the password for authentication.
     */
    public function password(): ?string;

    /**
     * Get driver-specific PDO options.
     */
    public function pdoOptions(): array;

    /**
     * SQL statements executed once, immediately after the connection opens
     * (e.g. SQLite "PRAGMA foreign_keys = ON"). Returns an empty array when the
     * driver needs no post-connect bootstrapping.
     *
     * @return list<string>
     */
    public function initStatements(): array;

    /**
     * Get all configuration as array. MUST NOT expose the password.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
