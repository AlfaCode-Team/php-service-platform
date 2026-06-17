<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * LazyDatabasePort — a DatabasePort that defers building the real adapter (and the
 * connection it opens) until the first method call.
 *
 * Used to wire the DatabaseErrorLogger notifier without forcing a database
 * connection at bootstrap: the error logger only ever touches the DB when an
 * error actually fires, so the underlying port stays unbuilt on the happy path.
 * The resolved port is memoised, so all writes in one process share one handle.
 */
final class LazyDatabasePort implements DatabasePort
{
    /** @var \Closure(): DatabasePort */
    private \Closure $factory;

    private ?DatabasePort $resolved = null;

    /** @param \Closure(): DatabasePort $factory */
    public function __construct(\Closure $factory)
    {
        $this->factory = $factory;
    }

    private function port(): DatabasePort
    {
        return $this->resolved ??= ($this->factory)();
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->port()->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->port()->queryOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->port()->execute($sql, $params);
    }

    public function lastInsertId(): string
    {
        return $this->port()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->port()->beginTransaction();
    }

    public function commit(): void
    {
        $this->port()->commit();
    }

    public function rollback(): void
    {
        $this->port()->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->port()->inTransaction();
    }
}
