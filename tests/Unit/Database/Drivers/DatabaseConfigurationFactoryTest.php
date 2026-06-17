<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\DatabaseConfigurationFactory;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Drivers\SqlServerConfiguration;
use Plugins\Database\Exceptions\ConnectionException;

#[CoversClass(DatabaseConfigurationFactory::class)]
final class DatabaseConfigurationFactoryTest extends TestCase
{
    private DatabaseConfigurationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new DatabaseConfigurationFactory();
    }

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function driverAliasProvider(): array
    {
        return [
            'mysql' => ['mysql', MySQLConfiguration::class],
            'mariadb alias' => ['mariadb', MySQLConfiguration::class],
            'postgresql' => ['postgresql', PostgreSQLConfiguration::class],
            'postgres alias' => ['postgres', PostgreSQLConfiguration::class],
            'pgsql alias' => ['pgsql', PostgreSQLConfiguration::class],
            'sqlite' => ['sqlite', SQLiteConfiguration::class],
            'sqlsrv' => ['sqlsrv', SqlServerConfiguration::class],
            'mssql alias' => ['mssql', SqlServerConfiguration::class],
            'sqlserver alias' => ['sqlserver', SqlServerConfiguration::class],
        ];
    }

    #[DataProvider('driverAliasProvider')]
    public function test_resolves_driver_aliases(string $driver, string $expectedClass): void
    {
        $config = $this->factory->make(['driver' => $driver, 'database' => 'app']);

        $this->assertInstanceOf($expectedClass, $config);
    }

    public function test_is_case_insensitive(): void
    {
        $config = $this->factory->make(['driver' => 'MySQL', 'database' => 'app']);

        $this->assertInstanceOf(MySQLConfiguration::class, $config);
    }

    public function test_defaults_to_sqlite_when_driver_missing(): void
    {
        $config = $this->factory->make([]);

        $this->assertInstanceOf(SQLiteConfiguration::class, $config);
    }

    public function test_unknown_driver_throws(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/Unsupported database driver/');

        $this->factory->make(['driver' => 'oracle']);
    }

    public function test_coerces_string_booleans_for_ssl(): void
    {
        $config = $this->factory->make([
            'driver' => 'mysql',
            'database' => 'app',
            'ssl_verify' => 'true',
            'ssl_ca' => '/ca.pem',
        ]);

        $this->assertArrayHasKey(\PDO::MYSQL_ATTR_SSL_CA, $config->pdoOptions());
    }

    public function test_sqlite_empty_database_becomes_memory(): void
    {
        $config = $this->factory->make(['driver' => 'sqlite', 'database' => '']);

        $this->assertSame('sqlite::memory:', $config->dsn());
    }

    public function test_from_environment_reads_db_driver(): void
    {
        putenv('DB_DRIVER=postgresql');
        putenv('DB_DATABASE=envdb');

        try {
            $config = $this->factory->fromEnvironment();
            $this->assertInstanceOf(PostgreSQLConfiguration::class, $config);
            $this->assertStringContainsString('dbname=envdb', $config->dsn());
        } finally {
            putenv('DB_DRIVER');
            putenv('DB_DATABASE');
        }
    }
}
