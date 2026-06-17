<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Pool;

use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

/**
 * PooledConnection — a pool slot wrapping one physical adapter plus the
 * bookkeeping the pool needs for lifetime and idle eviction.
 */
final class PooledConnection
{
    private float $lastUsedAt;

    public function __construct(
        public readonly MultiDriverDatabaseAdapter $adapter,
        public readonly float $createdAt,
    ) {
        $this->lastUsedAt = $createdAt;
    }

    public function touch(): void
    {
        $this->lastUsedAt = microtime(true);
    }

    /** Seconds since this connection was created. */
    public function ageSeconds(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /** Seconds since this connection was last used. */
    public function idleSeconds(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }
}
