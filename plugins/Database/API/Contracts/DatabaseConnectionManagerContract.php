<?php

declare(strict_types=1);

namespace Plugins\Database\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * DatabaseConnectionManagerContract — manages database connections and pooling.
 * Supports multiple named connections for multi-database scenarios.
 */
interface DatabaseConnectionManagerContract
{
    /**
     * Get a database connection by name.
     *
     * @throws \Plugins\Database\Exceptions\ConnectionException
     */
    public function connection(string $name = 'default'): DatabasePort;

    /**
     * Get the default connection.
     */
    public function default(): DatabasePort;

    /**
     * Register a new connection configuration.
     */
    public function register(string $name, DatabaseConfigurationContract $config): void;

    /**
     * Check if a connection exists.
     */
    public function has(string $name): bool;

    /**
     * Get all registered connection names.
     */
    public function connections(): array;

    /**
     * Close a connection.
     */
    public function close(string $name = 'default'): void;

    /**
     * Close all connections.
     */
    public function closeAll(): void;
}
