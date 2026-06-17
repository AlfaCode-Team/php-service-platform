<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SqlServerConfiguration;

#[CoversClass(SqlServerConfiguration::class)]
final class SqlServerConfigurationTest extends TestCase
{
    public function test_driver_is_sqlsrv(): void
    {
        $this->assertSame('sqlsrv', (new SqlServerConfiguration())->driver());
    }

    public function test_builds_dsn(): void
    {
        $config = new SqlServerConfiguration(
            server: 'sql.example.com',
            port: 1433,
            database: 'erp',
        );

        $this->assertSame('sqlsrv:Server=sql.example.com,1433;Database=erp', $config->dsn());
    }

    public function test_encryption_options_are_opt_in(): void
    {
        $plain = (new SqlServerConfiguration())->pdoOptions();
        $this->assertArrayNotHasKey('Encrypt', $plain);
        $this->assertArrayNotHasKey('TrustServerCertificate', $plain);

        $secure = (new SqlServerConfiguration(trustServerCertificate: true, encrypt: true))->pdoOptions();
        $this->assertTrue($secure['Encrypt']);
        $this->assertTrue($secure['TrustServerCertificate']);
    }

    public function test_init_statements_enable_xact_abort(): void
    {
        $this->assertContains('SET XACT_ABORT ON', (new SqlServerConfiguration())->initStatements());
    }

    public function test_to_array_hides_password(): void
    {
        $array = (new SqlServerConfiguration(password: 'secret', encrypt: true))->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertTrue($array['encrypt']);
    }
}
