<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Pool;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Pool\PoolConfiguration;

#[CoversClass(PoolConfiguration::class)]
final class PoolConfigurationTest extends TestCase
{
    public function test_sensible_defaults(): void
    {
        $config = new PoolConfiguration();

        $this->assertSame(0, $config->minConnections);
        $this->assertSame(10, $config->maxConnections);
        $this->assertTrue($config->validateOnAcquire);
    }

    public function test_rejects_zero_max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PoolConfiguration(maxConnections: 0);
    }

    public function test_rejects_min_greater_than_max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PoolConfiguration(minConnections: 5, maxConnections: 2);
    }

    public function test_rejects_negative_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PoolConfiguration(acquireTimeoutMs: -1);
    }

    public function test_from_environment_reads_pool_vars(): void
    {
        putenv('DB_POOL_MIN=2');
        putenv('DB_POOL_MAX=20');
        putenv('DB_POOL_ACQUIRE_TIMEOUT_MS=1500');
        putenv('DB_POOL_VALIDATE=false');

        try {
            $config = PoolConfiguration::fromEnvironment();
            $this->assertSame(2, $config->minConnections);
            $this->assertSame(20, $config->maxConnections);
            $this->assertSame(1500, $config->acquireTimeoutMs);
            $this->assertFalse($config->validateOnAcquire);
        } finally {
            putenv('DB_POOL_MIN');
            putenv('DB_POOL_MAX');
            putenv('DB_POOL_ACQUIRE_TIMEOUT_MS');
            putenv('DB_POOL_VALIDATE');
        }
    }

    public function test_db_pool_size_is_alias_for_max(): void
    {
        putenv('DB_POOL_SIZE=7');

        try {
            $this->assertSame(7, PoolConfiguration::fromEnvironment()->maxConnections);
        } finally {
            putenv('DB_POOL_SIZE');
        }
    }
}
