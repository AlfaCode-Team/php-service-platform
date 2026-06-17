<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;

#[CoversClass(SQLiteConfiguration::class)]
final class SQLiteConfigurationTest extends TestCase
{
    public function test_driver_is_sqlite(): void
    {
        $this->assertSame('sqlite', (new SQLiteConfiguration())->driver());
    }

    public function test_in_memory_dsn(): void
    {
        $this->assertSame('sqlite::memory:', (new SQLiteConfiguration(':memory:'))->dsn());
    }

    public function test_file_based_dsn(): void
    {
        $this->assertSame('sqlite:/data/app.sqlite', (new SQLiteConfiguration('/data/app.sqlite'))->dsn());
    }

    public function test_has_no_credentials(): void
    {
        $config = new SQLiteConfiguration();

        $this->assertNull($config->username());
        $this->assertNull($config->password());
    }

    public function test_in_memory_omits_wal_pragma(): void
    {
        $statements = (new SQLiteConfiguration(':memory:'))->initStatements();

        $this->assertContains('PRAGMA foreign_keys = ON', $statements);
        $this->assertNotContains('PRAGMA journal_mode = WAL', $statements);
    }

    public function test_file_based_enables_wal(): void
    {
        $statements = (new SQLiteConfiguration('/data/app.sqlite'))->initStatements();

        $this->assertContains('PRAGMA foreign_keys = ON', $statements);
        $this->assertContains('PRAGMA journal_mode = WAL', $statements);
    }

    public function test_pdo_options_carry_open_flags(): void
    {
        $options = (new SQLiteConfiguration())->pdoOptions();

        $this->assertArrayHasKey(PDO::SQLITE_ATTR_OPEN_FLAGS, $options);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }

    public function test_to_array_flags_in_memory(): void
    {
        $this->assertTrue((new SQLiteConfiguration(':memory:'))->toArray()['in_memory']);
        $this->assertFalse((new SQLiteConfiguration('/data/app.sqlite'))->toArray()['in_memory']);
    }
}
