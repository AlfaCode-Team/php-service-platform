<?php

declare(strict_types=1);

namespace Plugins\Commands\Exceptions;

final class ConfigurationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $context = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidDriver(string $driver): self
    {
        return new self(
            "Invalid database driver: {$driver}. Supported: mysql, pgsql, sqlite, sqlsrv",
            context: 'database.driver'
        );
    }

    public static function missingConnection(string $key): self
    {
        return new self(
            "Required connection key missing: {$key}",
            context: 'database.connection'
        );
    }

    public static function emptyMigrationPaths(): self
    {
        return new self(
            'At least one migration path must be configured',
            context: 'migrations.paths'
        );
    }

    public static function fileNotFound(string $path): self
    {
        return new self(
            "Configuration file not found: {$path}",
            context: 'file.not_found'
        );
    }

    public static function loadFailed(string $path, \Throwable $e): self
    {
        return new self(
            "Failed to load configuration from {$path}: {$e->getMessage()}",
            context: 'file.load_error',
            previous: $e
        );
    }

    public static function invalidStructure(string $detail): self
    {
        return new self(
            "Invalid configuration structure: {$detail}",
            context: 'structure'
        );
    }
}
