<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Pool;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Infrastructure\Pool\ConnectionPool;
use Plugins\Database\Infrastructure\Pool\PooledConnection;
use Plugins\Database\Infrastructure\Pool\PoolConfiguration;
use Plugins\Database\Exceptions\ConnectionException;

#[CoversClass(ConnectionPool::class)]
#[CoversClass(PooledConnection::class)]
final class ConnectionPoolTest extends TestCase
{
    private function pool(PoolConfiguration $config): ConnectionPool
    {
        return new ConnectionPool(
            factory: static fn (): MultiDriverDatabaseAdapter =>
                new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:')),
            config: $config,
            driver: 'sqlite',
        );
    }

    public function test_acquire_returns_working_connection(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 2));

        $conn = $pool->acquire();

        $this->assertInstanceOf(MultiDriverDatabaseAdapter::class, $conn);
        $this->assertTrue($conn->ping());
    }

    public function test_released_connection_is_reused(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 2));

        $first = $pool->acquire();
        $pool->release($first);
        $second = $pool->acquire();

        $this->assertSame($first, $second, 'A released connection should be handed back out.');
    }

    public function test_grows_up_to_max(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 3));

        $a = $pool->acquire();
        $b = $pool->acquire();
        $c = $pool->acquire();

        $this->assertNotSame($a, $b);
        $this->assertNotSame($b, $c);
        $this->assertSame(3, $pool->stats()['active']);
    }

    public function test_exhausted_pool_throws_after_timeout(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 1, acquireTimeoutMs: 50));

        $pool->acquire(); // holds the only slot

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/pool exhausted/i');
        $pool->acquire();
    }

    public function test_stats_track_active_and_idle(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 2));

        $conn = $pool->acquire();
        $this->assertSame(1, $pool->stats()['active']);
        $this->assertSame(0, $pool->stats()['idle']);

        $pool->release($conn);
        $this->assertSame(0, $pool->stats()['active']);
        $this->assertSame(1, $pool->stats()['idle']);
    }

    public function test_warmup_opens_min_connections(): void
    {
        $pool = $this->pool(new PoolConfiguration(minConnections: 2, maxConnections: 5));
        $pool->warmup();

        $this->assertSame(2, $pool->stats()['idle']);
        $this->assertSame(2, $pool->stats()['total']);
    }

    public function test_warmup_is_idempotent(): void
    {
        $pool = $this->pool(new PoolConfiguration(minConnections: 1, maxConnections: 5));
        $pool->warmup();
        $pool->warmup();

        $this->assertSame(1, $pool->stats()['total']);
    }

    public function test_dirty_connection_is_rolled_back_on_release(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 1));

        $conn = $pool->acquire();
        $conn->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $conn->beginTransaction();
        $conn->execute('INSERT INTO t (id) VALUES (1)');
        $pool->release($conn); // still in a transaction

        $reused = $pool->acquire();
        $this->assertSame($conn, $reused);
        $this->assertFalse($reused->inTransaction(), 'Open transaction must be rolled back before reuse.');
        $this->assertCount(0, $reused->query('SELECT id FROM t'));
    }

    public function test_idle_connection_past_lifetime_is_recycled(): void
    {
        // maxLifetime of 0 disables the lifetime check, so use a tiny positive
        // value and sleep past it.
        $pool = $this->pool(new PoolConfiguration(maxConnections: 2, maxLifetimeSec: 1));

        $first = $pool->acquire();
        $pool->release($first);

        // Age the connection beyond its lifetime.
        usleep(1_100_000);

        $second = $pool->acquire();
        $this->assertNotSame($first, $second, 'Expired connection should be replaced.');
    }

    public function test_foreign_release_is_ignored(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 2));
        $stranger = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));

        $pool->release($stranger); // not borrowed from this pool

        $this->assertSame(0, $pool->stats()['idle']);
        $this->assertSame(0, $pool->stats()['active']);
    }

    public function test_acquire_on_closed_pool_throws(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 1));
        $pool->close();

        $this->assertTrue($pool->isClosed());
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/closed pool/i');
        $pool->acquire();
    }

    public function test_release_into_closed_pool_drops_connection(): void
    {
        $pool = $this->pool(new PoolConfiguration(maxConnections: 1));
        $conn = $pool->acquire();
        $pool->close();

        $pool->release($conn); // should not repopulate idle
        $this->assertSame(0, $pool->stats()['idle']);
    }
}
