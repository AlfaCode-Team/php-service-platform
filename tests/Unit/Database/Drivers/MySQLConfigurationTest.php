<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Drivers;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;

#[CoversClass(MySQLConfiguration::class)]
final class MySQLConfigurationTest extends TestCase
{
    public function test_driver_is_mysql(): void
    {
        $config = new MySQLConfiguration();

        $this->assertSame('mysql', $config->driver());
    }

    public function test_builds_tcp_dsn(): void
    {
        $config = new MySQLConfiguration(
            host: 'db.example.com',
            port: 3307,
            database: 'shop',
            charset: 'utf8mb4',
        );

        $this->assertSame(
            'mysql:host=db.example.com;port=3307;dbname=shop;charset=utf8mb4',
            $config->dsn(),
        );
    }

    public function test_builds_unix_socket_dsn(): void
    {
        $config = new MySQLConfiguration(
            database: 'shop',
            unixSocket: '/var/run/mysqld/mysqld.sock',
        );

        $this->assertSame(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=shop;charset=utf8mb4',
            $config->dsn(),
        );
    }

    public function test_empty_credentials_become_null(): void
    {
        $config = new MySQLConfiguration(username: '', password: '');

        $this->assertNull($config->username());
        $this->assertNull($config->password());
    }

    public function test_pdo_options_enforce_exceptions_and_real_prepares(): void
    {
        $options = (new MySQLConfiguration())->pdoOptions();

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
    }

    public function test_ssl_options_only_present_when_verify_enabled(): void
    {
        $without = (new MySQLConfiguration(sslCa: '/ca.pem', useSslVerify: false))->pdoOptions();
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_CA, $without);

        $with = (new MySQLConfiguration(useSslVerify: true, sslCa: '/ca.pem'))->pdoOptions();
        $this->assertSame('/ca.pem', $with[PDO::MYSQL_ATTR_SSL_CA]);
        $this->assertTrue($with[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_init_statements_enforce_strict_mode(): void
    {
        $statements = (new MySQLConfiguration())->initStatements();

        $this->assertNotEmpty($statements);
        $this->assertStringContainsString('STRICT_ALL_TABLES', $statements[0]);
    }

    public function test_to_array_never_exposes_password(): void
    {
        $array = (new MySQLConfiguration(password: 'super-secret'))->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertSame('mysql', $array['driver']);
    }
}
