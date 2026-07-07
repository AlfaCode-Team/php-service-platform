<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Drivers;

use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\Exceptions\ConnectionException;

/**
 * DatabaseConfigurationFactory — builds a driver-specific configuration from a
 * normalised settings array.
 *
 * Centralises driver-alias resolution and per-driver defaults so the Provider
 * stays a thin wiring layer and the logic is unit-testable without env vars.
 */
final class DatabaseConfigurationFactory
{
    /**
     * Canonical driver aliases → internal driver key.
     */
    private const ALIASES = [
        'mysql' => 'mysql',
        'mariadb' => 'mysql',
        'pgsql' => 'pgsql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite' => 'sqlite',
        'sqlite3' => 'sqlite',
        'sqlsrv' => 'sqlsrv',
        'mssql' => 'sqlsrv',
        'sqlserver' => 'sqlsrv',
        'sql-server' => 'sqlsrv',
    ];

    /**
     * Build a configuration from a settings array.
     *
     * Recognised keys: driver, host, port, database, username, password,
     * charset, ssl_mode, ssl_verify, ssl_ca, unix_socket, trust_server_certificate,
     * encrypt.
     *
     * @param array<string, mixed> $settings
     * @throws ConnectionException when the driver is unknown
     */
    public function make(array $settings): DatabaseConfigurationContract
    {
        $requested = strtolower((string) ($settings['driver'] ?? 'sqlite'));
        $driver = self::ALIASES[$requested] ?? null;

        if ($driver === null) {
            throw ConnectionException::unsupportedDriver($requested);
        }

        return match ($driver) {
            'mysql' => $this->mysql($settings),
            'pgsql' => $this->postgres($settings),
            'sqlite' => $this->sqlite($settings),
            'sqlsrv' => $this->sqlServer($settings),
        };
    }

    /**
     * Build a configuration by reading DB_* environment variables.
     */
    public function fromEnvironment(): DatabaseConfigurationContract
    {
        $env = static fn (string $key): ?string => ($v = env($key)) === false ? null : $v;

        return $this->make([
            'driver' => $env('DB_DRIVER') ?? 'sqlite',
            'host' => $env('DB_HOST'),
            'port' => $env('DB_PORT'),
            'database' => $env('DB_DATABASE') ?? $env('DB_NAME'),
            'username' => $env('DB_USERNAME'),
            'password' => $env('DB_PASSWORD'),
            'charset' => $env('DB_CHARSET'),
            'ssl_mode' => $env('DB_SSL_MODE'),
            'ssl_verify' => $env('DB_SSL_VERIFY'),
            'ssl_ca' => $env('DB_SSL_CA'),
            'unix_socket' => $env('DB_UNIX_SOCKET'),
            'trust_server_certificate' => $env('DB_TRUST_SERVER_CERT'),
            'encrypt' => $env('DB_ENCRYPT'),
        ]);
    }

    private function mysql(array $s): MySQLConfiguration
    {
        return new MySQLConfiguration(
            host: (string) ($s['host'] ?? 'localhost'),
            port: (int) ($s['port'] ?? 3306),
            database: (string) ($s['database'] ?? ''),
            username: (string) ($s['username'] ?? 'root'),
            password: (string) ($s['password'] ?? ''),
            charset: (string) ($s['charset'] ?? 'utf8mb4'),
            useSslVerify: $this->bool($s['ssl_verify'] ?? false),
            sslCa: $this->nullableString($s['ssl_ca'] ?? null),
            unixSocket: $this->nullableString($s['unix_socket'] ?? null),
        );
    }

    private function postgres(array $s): PostgreSQLConfiguration
    {
        return new PostgreSQLConfiguration(
            host: (string) ($s['host'] ?? 'localhost'),
            port: (int) ($s['port'] ?? 5432),
            database: (string) ($s['database'] ?? 'postgres'),
            username: (string) ($s['username'] ?? 'postgres'),
            password: (string) ($s['password'] ?? ''),
            sslMode: (string) ($s['ssl_mode'] ?? 'prefer'),
            unixSocket: $this->nullableString($s['unix_socket'] ?? null),
        );
    }

    private function sqlite(array $s): SQLiteConfiguration
    {
        $path = $this->nullableString($s['database'] ?? null) ?? ':memory:';

        return new SQLiteConfiguration(path: $path);
    }

    private function sqlServer(array $s): SqlServerConfiguration
    {
        return new SqlServerConfiguration(
            server: (string) ($s['host'] ?? 'localhost'),
            port: (int) ($s['port'] ?? 1433),
            database: (string) ($s['database'] ?? ''),
            username: (string) ($s['username'] ?? 'sa'),
            password: (string) ($s['password'] ?? ''),
            trustServerCertificate: $this->bool($s['trust_server_certificate'] ?? false),
            encrypt: $this->bool($s['encrypt'] ?? false),
        );
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
