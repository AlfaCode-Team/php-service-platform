<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Database\Infrastructure\Pool\ConnectionPool;

/**
 * PooledDatabaseAdapter — the request-scoped DatabasePort that fronts a shared
 * ConnectionPool.
 *
 * Lifetime: ONE instance per request (bound into the request-scoped
 * ModuleContainer). On first use it borrows a single physical connection from
 * the per-worker pool and pins it for the whole request, so:
 *
 *   • every query in the request runs on the same connection — lastInsertId()
 *     and multi-statement transactions stay correct;
 *   • the connection is returned to the pool at end-of-request (release(), with
 *     __destruct as a safety net), ready for the next request to reuse.
 *
 * Because each request gets its own instance and (by default) requests run
 * sequentially per worker, the pinned-connection field needs no per-coroutine
 * keying. Repositories depend on DatabasePort and never see this class.
 */
final class PooledDatabaseAdapter implements DatabasePort
{
    private ?MultiDriverDatabaseAdapter $pinned = null;

    public function __construct(
        private readonly ConnectionPool $pool,
    ) {}

    /**
     * The connection bound to this request, borrowed lazily on first access.
     */
    public function connection(): MultiDriverDatabaseAdapter
    {
        return $this->pinned ??= $this->pool->acquire();
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->connection()->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->connection()->queryOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->connection()->execute($sql, $params);
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        return $this->connection()->upsert($table, $values, $conflictColumns, $updateColumns);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return $this->connection()->lastInsertId($sequence);
    }

    public function beginTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection()->commit();
    }

    public function rollback(): void
    {
        $this->connection()->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->pinned !== null && $this->pinned->inTransaction();
    }

    /**
     * Run $work inside a transaction on the pinned connection.
     *
     * @template T
     * @param callable(MultiDriverDatabaseAdapter): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return $this->connection()->transaction($work);
    }

    /**
     * Return the borrowed connection to the pool. Safe to call repeatedly.
     * Invoked at end-of-request; idempotent.
     */
    public function release(): void
    {
        if ($this->pinned !== null) {
            $this->pool->release($this->pinned);
            $this->pinned = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
