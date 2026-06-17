<?php

declare(strict_types=1);

namespace Plugins\Database\Exceptions;

/**
 * ConnectionException — the single exception type that escapes the Database module.
 *
 * All native \PDOException instances are translated into this class so that callers
 * (repositories) never have to catch a vendor exception. Carries structured context
 * (driver + operation) for the kernel ErrorPipeline.
 */
final class ConnectionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $driver = '',
        public readonly string $operation = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function connectionFailed(string $driver, string $reason, \Throwable $e): self
    {
        return new self(
            "Failed to connect to {$driver} database: {$reason}",
            driver: $driver,
            operation: 'connect',
            previous: $e,
        );
    }

    public static function connectionLost(string $driver, string $reason, \Throwable $e): self
    {
        return new self(
            "Lost connection to {$driver} database: {$reason}",
            driver: $driver,
            operation: 'connection_lost',
            previous: $e,
        );
    }

    public static function queryFailed(string $driver, string $sql, string $reason, \Throwable $e): self
    {
        return new self(
            "Query failed on {$driver}: {$reason}\nSQL: {$sql}",
            driver: $driver,
            operation: 'query',
            previous: $e,
        );
    }

    public static function executionFailed(string $driver, string $sql, string $reason, \Throwable $e): self
    {
        return new self(
            "Execution failed on {$driver}: {$reason}\nSQL: {$sql}",
            driver: $driver,
            operation: 'execute',
            previous: $e,
        );
    }

    public static function transactionFailed(string $driver, string $operation, string $reason, \Throwable $e): self
    {
        return new self(
            "Transaction {$operation} failed on {$driver}: {$reason}",
            driver: $driver,
            operation: "transaction.{$operation}",
            previous: $e,
        );
    }

    public static function unsupportedDriver(string $driver): self
    {
        return new self(
            "Unsupported database driver: '{$driver}'. Supported: mysql, postgresql, sqlite, sqlsrv.",
            driver: $driver,
            operation: 'resolve_driver',
        );
    }

    public static function unknownConnection(string $name): self
    {
        return new self(
            "No database connection registered under name '{$name}'.",
            operation: 'resolve_connection',
        );
    }

    public static function poolExhausted(string $driver, int $max, int $timeoutMs): self
    {
        return new self(
            "Connection pool exhausted: all {$max} {$driver} connections are in use "
            . "and none became free within {$timeoutMs}ms.",
            driver: $driver,
            operation: 'pool_acquire',
        );
    }

    public static function poolClosed(string $driver): self
    {
        return new self(
            "Cannot acquire a {$driver} connection from a closed pool.",
            driver: $driver,
            operation: 'pool_acquire',
        );
    }
}
