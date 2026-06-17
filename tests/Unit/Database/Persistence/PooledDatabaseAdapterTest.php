<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Infrastructure\Persistence\PooledDatabaseAdapter;
use Plugins\Database\Infrastructure\Pool\ConnectionPool;
use Plugins\Database\Infrastructure\Pool\PoolConfiguration;

#[CoversClass(PooledDatabaseAdapter::class)]
final class PooledDatabaseAdapterTest extends TestCase
{
    private ConnectionPool $pool;

    protected function setUp(): void
    {
        // A single shared SQLite file would be needed for cross-connection
        // visibility; for these tests max=1 keeps every borrow on one in-memory
        // connection so schema persists within a test.
        $this->pool = new ConnectionPool(
            factory: static fn (): MultiDriverDatabaseAdapter =>
                new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:')),
            config: new PoolConfiguration(maxConnections: 1),
            driver: 'sqlite',
        );
    }

    public function test_is_a_database_port(): void
    {
        $this->assertInstanceOf(DatabasePort::class, new PooledDatabaseAdapter($this->pool));
    }

    public function test_borrows_lazily_on_first_use(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);

        $this->assertSame(0, $this->pool->stats()['active']);
        $adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $this->assertSame(1, $this->pool->stats()['active']);
    }

    public function test_crud_runs_on_pinned_connection(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);
        $adapter->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');

        $adapter->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

        $this->assertSame('1', $adapter->lastInsertId());
        $this->assertSame('Alice', $adapter->queryOne('SELECT name FROM users WHERE id = 1')['name']);
    }

    public function test_release_returns_connection_to_pool(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);
        $adapter->query('SELECT 1');

        $this->assertSame(1, $this->pool->stats()['active']);
        $adapter->release();
        $this->assertSame(0, $this->pool->stats()['active']);
        $this->assertSame(1, $this->pool->stats()['idle']);
    }

    public function test_release_is_idempotent(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);
        $adapter->query('SELECT 1');

        $adapter->release();
        $adapter->release();

        $this->assertSame(0, $this->pool->stats()['active']);
    }

    public function test_reborrows_after_release(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);
        $adapter->query('SELECT 1');
        $adapter->release();

        $adapter->query('SELECT 1'); // borrows again
        $this->assertSame(1, $this->pool->stats()['active']);
    }

    public function test_transaction_commits_on_pinned_connection(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);
        $adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');

        $adapter->transaction(function (MultiDriverDatabaseAdapter $db): void {
            $db->execute('INSERT INTO t (id) VALUES (1)');
        });

        $this->assertCount(1, $adapter->query('SELECT id FROM t'));
        $this->assertFalse($adapter->inTransaction());
    }

    public function test_in_transaction_reflects_pinned_state(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);

        $this->assertFalse($adapter->inTransaction());
        $adapter->beginTransaction();
        $this->assertTrue($adapter->inTransaction());
        $adapter->rollback();
        $this->assertFalse($adapter->inTransaction());
    }

    public function test_open_transaction_is_cleaned_up_on_release(): void
    {
        $adapter = new PooledDatabaseAdapter($this->pool);
        $adapter->execute('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $adapter->beginTransaction();
        $adapter->execute('INSERT INTO t (id) VALUES (1)');

        $adapter->release(); // pool rolls back the dirty connection

        $reuse = new PooledDatabaseAdapter($this->pool);
        $this->assertFalse($reuse->inTransaction());
        $this->assertCount(0, $reuse->query('SELECT id FROM t'));
    }
}
