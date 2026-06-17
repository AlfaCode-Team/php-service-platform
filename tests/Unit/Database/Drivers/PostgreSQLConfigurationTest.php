<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;

#[CoversClass(PostgreSQLConfiguration::class)]
final class PostgreSQLConfigurationTest extends TestCase
{
    public function test_driver_is_pgsql(): void
    {
        $this->assertSame('pgsql', (new PostgreSQLConfiguration())->driver());
    }

    public function test_builds_tcp_dsn_with_ssl_mode(): void
    {
        $config = new PostgreSQLConfiguration(
            host: 'pg.example.com',
            port: 5433,
            database: 'analytics',
            sslMode: 'require',
        );

        $this->assertSame(
            'pgsql:host=pg.example.com;port=5433;dbname=analytics;sslmode=require',
            $config->dsn(),
        );
    }

    public function test_builds_unix_socket_dsn(): void
    {
        $config = new PostgreSQLConfiguration(
            database: 'analytics',
            unixSocket: '/var/run/postgresql',
        );

        $this->assertSame(
            'pgsql:host=/var/run/postgresql;dbname=analytics;sslmode=prefer',
            $config->dsn(),
        );
    }

    public function test_pdo_options_disable_emulated_prepares(): void
    {
        $options = (new PostgreSQLConfiguration())->pdoOptions();

        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }

    public function test_has_no_init_statements(): void
    {
        $this->assertSame([], (new PostgreSQLConfiguration())->initStatements());
    }

    public function test_to_array_exposes_ssl_mode_not_password(): void
    {
        $array = (new PostgreSQLConfiguration(password: 'secret', sslMode: 'verify-full'))->toArray();

        $this->assertSame('verify-full', $array['ssl_mode']);
        $this->assertArrayNotHasKey('password', $array);
    }
}
