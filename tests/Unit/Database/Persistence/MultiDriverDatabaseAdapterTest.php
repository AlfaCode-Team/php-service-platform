<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Infrastructure\Persistence\SavepointGrammar;
use Plugins\Database\Exceptions\ConnectionException;

#[CoversClass(MultiDriverDatabaseAdapter::class)]
#[CoversClass(SavepointGrammar::class)]
final class MultiDriverDatabaseAdapterTest extends TestCase
{
    private MultiDriverDatabaseAdapter $db;

    protected function setUp(): void
    {
        $this->db = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    }

    public function test_is_not_connected_until_first_use(): void
    {
        $fresh = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));

        $this->assertFalse($fresh->isConnected());
        $fresh->pdo();
        $this->assertTrue($fresh->isConnected());
    }

    public function test_execute_returns_affected_rows(): void
    {
        $affected = $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

        $this->assertSame(1, $affected);
    }

    public function test_query_returns_all_rows(): void
    {
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Bob']);

        $rows = $this->db->query('SELECT name FROM users ORDER BY name');

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['name']);
        $this->assertSame('Bob', $rows[1]['name']);
    }

    public function test_query_one_returns_single_row(): void
    {
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

        $row = $this->db->queryOne('SELECT name FROM users WHERE name = ?', ['Alice']);

        $this->assertSame('Alice', $row['name']);
    }

    public function test_query_one_returns_null_when_missing(): void
    {
        $this->assertNull($this->db->queryOne('SELECT name FROM users WHERE name = ?', ['Nobody']));
    }

    public function test_last_insert_id_tracks_inserts(): void
    {
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);

        $this->assertSame('1', $this->db->lastInsertId());
    }

    public function test_driver_is_exposed(): void
    {
        $this->assertSame('sqlite', $this->db->driver());
    }

    public function test_ping_succeeds_on_healthy_connection(): void
    {
        $this->assertTrue($this->db->ping());
    }

    public function test_close_drops_connection_and_reconnects_lazily(): void
    {
        $this->db->query('SELECT 1');
        $this->assertTrue($this->db->isConnected());

        $this->db->close();
        $this->assertFalse($this->db->isConnected());

        // Next use transparently reconnects (a fresh :memory: db).
        $this->assertSame([], $this->db->query('SELECT 1 WHERE 1 = 0'));
        $this->assertTrue($this->db->isConnected());
    }

    public function test_close_resets_transaction_level(): void
    {
        $this->db->beginTransaction();
        $this->db->close();

        $this->assertSame(0, $this->db->transactionLevel());
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_foreign_keys_pragma_is_enabled(): void
    {
        $row = $this->db->queryOne('PRAGMA foreign_keys');

        $this->assertSame(1, (int) $row['foreign_keys']);
    }

    // ───────────────────────────────────── transactions ─────────────────────

    public function test_commit_persists_changes(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
        $this->db->commit();

        $this->assertCount(1, $this->db->query('SELECT id FROM users'));
    }

    public function test_rollback_discards_changes(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
        $this->db->rollback();

        $this->assertCount(0, $this->db->query('SELECT id FROM users'));
    }

    public function test_in_transaction_reflects_state(): void
    {
        $this->assertFalse($this->db->inTransaction());
        $this->db->beginTransaction();
        $this->assertTrue($this->db->inTransaction());
        $this->db->commit();
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_nested_transactions_track_depth(): void
    {
        $this->db->beginTransaction();
        $this->assertSame(1, $this->db->transactionLevel());

        $this->db->beginTransaction();
        $this->assertSame(2, $this->db->transactionLevel());

        $this->db->commit();
        $this->assertSame(1, $this->db->transactionLevel());

        $this->db->commit();
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function test_inner_rollback_keeps_outer_work_via_savepoint(): void
    {
        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Outer']);

        $this->db->beginTransaction();
        $this->db->execute('INSERT INTO users (name) VALUES (?)', ['Inner']);
        $this->db->rollback(); // rolls back to savepoint, keeps "Outer"

        $this->db->commit();

        $names = array_column($this->db->query('SELECT name FROM users'), 'name');
        $this->assertContains('Outer', $names);
        $this->assertNotContains('Inner', $names);
    }

    public function test_transaction_helper_commits_on_success(): void
    {
        $result = $this->db->transaction(function (MultiDriverDatabaseAdapter $db): string {
            $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertCount(1, $this->db->query('SELECT id FROM users'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_transaction_helper_rolls_back_on_exception(): void
    {
        try {
            $this->db->transaction(function (MultiDriverDatabaseAdapter $db): void {
                $db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertCount(0, $this->db->query('SELECT id FROM users'));
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_commit_without_active_transaction_is_noop(): void
    {
        $this->db->commit();
        $this->assertSame(0, $this->db->transactionLevel());
    }

    public function test_rollback_without_active_transaction_is_noop(): void
    {
        $this->db->rollback();
        $this->assertSame(0, $this->db->transactionLevel());
    }

    // ───────────────────────────────────── error translation ────────────────

    public function test_invalid_sql_throws_connection_exception(): void
    {
        $this->expectException(ConnectionException::class);

        $this->db->query('SELECT * FROM table_that_does_not_exist');
    }

    public function test_connection_exception_carries_driver_and_operation(): void
    {
        try {
            $this->db->execute('THIS IS NOT SQL');
            $this->fail('Expected ConnectionException');
        } catch (ConnectionException $e) {
            $this->assertSame('sqlite', $e->driver);
            $this->assertSame('execute', $e->operation);
        }
    }

    public function test_failed_connection_throws_on_unwritable_path(): void
    {
        $adapter = new MultiDriverDatabaseAdapter(
            new SQLiteConfiguration('/nonexistent-dir/cannot/create.sqlite'),
        );

        $this->expectException(ConnectionException::class);
        $adapter->pdo();
    }
}
