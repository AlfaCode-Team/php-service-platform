<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Pool;

use InvalidArgumentException;

/**
 * PoolConfiguration — immutable tuning parameters for a ConnectionPool.
 *
 *   minConnections     Connections opened eagerly at warm-up (kept hot).
 *   maxConnections     Hard ceiling on concurrent physical connections.
 *   acquireTimeoutMs   How long acquire() waits for a free slot before failing.
 *   idleTimeoutSec     An idle connection older than this is evicted on borrow.
 *   maxLifetimeSec     A connection older than this (since creation) is recycled.
 *   validateOnAcquire  ping() a reused connection before handing it out.
 */
final readonly class PoolConfiguration
{
    public function __construct(
        public int $minConnections = 0,
        public int $maxConnections = 10,
        public int $acquireTimeoutMs = 3000,
        public int $idleTimeoutSec = 60,
        public int $maxLifetimeSec = 3600,
        public bool $validateOnAcquire = true,
    ) {
        if ($this->maxConnections < 1) {
            throw new InvalidArgumentException('maxConnections must be >= 1.');
        }
        if ($this->minConnections < 0 || $this->minConnections > $this->maxConnections) {
            throw new InvalidArgumentException('minConnections must be between 0 and maxConnections.');
        }
        if ($this->acquireTimeoutMs < 0) {
            throw new InvalidArgumentException('acquireTimeoutMs must be >= 0.');
        }
        if ($this->idleTimeoutSec < 0 || $this->maxLifetimeSec < 0) {
            throw new InvalidArgumentException('Timeouts must be >= 0.');
        }
    }

    /**
     * Build pool settings from DB_POOL_* environment variables.
     */
    public static function fromEnvironment(): self
    {
        $int = static function (string $key, int $default): int {
            $value = env($key);

            return $value === null || $value === false || $value === '' ? $default : (int) $value;
        };
        $bool = static function (string $key, bool $default): bool {
            $value = env($key);
            if ($value === null || $value === false || $value === '') {
                return $default;
            }

            return \in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
        };

        // DB_POOL_SIZE is accepted as an alias for the maximum size.
        $max = $int('DB_POOL_MAX', $int('DB_POOL_SIZE', 10));

        return new self(
            minConnections: $int('DB_POOL_MIN', 0),
            maxConnections: $max,
            acquireTimeoutMs: $int('DB_POOL_ACQUIRE_TIMEOUT_MS', 3000),
            idleTimeoutSec: $int('DB_POOL_IDLE_TIMEOUT', 60),
            maxLifetimeSec: $int('DB_POOL_MAX_LIFETIME', 3600),
            validateOnAcquire: $bool('DB_POOL_VALIDATE', true),
        );
    }
}
