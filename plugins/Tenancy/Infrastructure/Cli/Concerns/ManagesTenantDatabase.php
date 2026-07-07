<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Cli\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Shared driver-aware database/user provisioning + teardown helpers for the
 * tenant CLI commands. DDL (CREATE/DROP DATABASE, CREATE/DROP USER, GRANT) is
 * NOT transactional on MySQL — it implicitly commits — so "rollback" is done by
 * compensating teardown (dropping what was created), not a SQL transaction.
 */
trait ManagesTenantDatabase
{
    /**
     * MySQL host part(s) for a tenant account. A loopback DB host grants the
     * full local set (socket + IPv4 + IPv6 loopback); any other host is pinned
     * to exactly that host. The wildcard '%' is NEVER used.
     *
     * @return list<string>
     */
    protected function grantHosts(string $dbHost): array
    {
        $local = ['localhost', '127.0.0.1', '::1', ''];

        return in_array(strtolower($dbHost), $local, true)
            ? ['localhost', '127.0.0.1', '::1']
            : [$dbHost];
    }

    /** Does the physical database already exist? (Driver-aware catalogue lookup.) */
    protected function databaseExists(DatabasePort $db, string $driver, string $dbName): bool
    {
        $row = match ($driver) {
            'pgsql'  => $db->queryOne('SELECT 1 AS present FROM pg_database WHERE datname = :db', ['db' => $dbName]),
            'sqlsrv' => $db->queryOne('SELECT 1 AS present FROM sys.databases WHERE name = :db', ['db' => $dbName]),
            default  => $db->queryOne('SELECT 1 AS present FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db', ['db' => $dbName]),
        };

        return $row !== null;
    }

    /** Drop the physical database (idempotent, driver-aware). */
    protected function dropDatabase(DatabasePort $db, string $driver, string $dbName): void
    {
        match ($driver) {
            'pgsql'  => $db->execute("DROP DATABASE IF EXISTS \"{$dbName}\""),
            'sqlsrv' => $db->execute("IF DB_ID('{$dbName}') IS NOT NULL DROP DATABASE [{$dbName}]"),
            default  => $db->execute("DROP DATABASE IF EXISTS `{$dbName}`"),
        };
    }

    /** Drop the tenant account (idempotent, across every grant host on MySQL). */
    protected function dropDatabaseUser(DatabasePort $db, string $driver, string $dbUser, string $dbHost): void
    {
        if ($driver === 'pgsql') {
            $db->execute("DROP ROLE IF EXISTS \"{$dbUser}\"");

            return;
        }

        if ($driver === 'sqlsrv') {
            $login = str_replace(']', ']]', $dbUser);
            $db->execute("IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = '{$dbUser}') DROP LOGIN [{$login}]");

            return;
        }

        // MySQL / MariaDB — one account per grant host.
        foreach ($this->grantHosts($dbHost) as $host) {
            $db->execute("DROP USER IF EXISTS '{$dbUser}'@'{$host}'");
        }
        $db->execute('FLUSH PRIVILEGES');
    }
}
