<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Provisioning;

use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Infrastructure\Cli\Concerns\ManagesTenantDatabase;

/**
 * DdlTenantProvisioner — the data-plane adapter behind {@see TenantProvisioner}.
 *
 * Owns the non-transactional DDL (CREATE/DROP DATABASE, CREATE/DROP USER, GRANT)
 * and the template-migration run — exactly what the tenant:create CLI command
 * does, but reusable from the HTTP control plane. Identifiers are validated by
 * the calling service before they ever reach the inlined DDL here.
 */
final class DdlTenantProvisioner implements TenantProvisioner
{
    use ManagesTenantDatabase {
        databaseExists as private databaseExistsRaw;
    }

    public function __construct(
        private readonly DatabasePort $central,
        private readonly string $templatePath,
    ) {}

    public function databaseExists(Tenant $tenant): bool
    {
        return $this->databaseExistsRaw($this->central, $tenant->dbDriver, $tenant->dbName);
    }

    public function provision(Tenant $tenant, string $plainPassword, bool $databaseAlreadyExists): void
    {
        $driver = $tenant->dbDriver;
        $dbName = $tenant->dbName;

        // 1. Create the isolated database (idempotent, driver-aware).
        if (!$databaseAlreadyExists) {
            match ($driver) {
                'pgsql'  => $this->central->execute("CREATE DATABASE \"{$dbName}\""),
                'sqlsrv' => $this->central->execute("IF DB_ID('{$dbName}') IS NULL CREATE DATABASE [{$dbName}]"),
                default  => $this->central->execute("CREATE DATABASE IF NOT EXISTS `{$dbName}`"),
            };
        }

        // 2. Create the tenant DB user, granted on its database only.
        $this->provisionUser($driver, $dbName, $tenant->dbUsername, $plainPassword, $tenant->dbHost);

        // 3. Run the tenant template migrations against the new database.
        $service = MigrationServiceFactory::fromConfig([
            'driver'        => $driver,
            'host'          => $tenant->dbHost,
            'port'          => $tenant->dbPort,
            'database'      => $dbName,
            'username'      => $tenant->dbUsername,
            'password'      => $plainPassword,
            'paths'         => [$this->templatePath],
            'transactional' => true,
        ]);
        $service->install();
        $service->run();
    }

    public function teardown(Tenant $tenant, bool $dropDatabase): int
    {
        $failed = 0;

        try {
            $this->dropDatabaseUser($this->central, $tenant->dbDriver, $tenant->dbUsername, $tenant->dbHost);
        } catch (\Throwable) {
            $failed++;
        }

        if ($dropDatabase) {
            try {
                $this->dropDatabase($this->central, $tenant->dbDriver, $tenant->dbName);
            } catch (\Throwable) {
                $failed++;
            }
        }

        return $failed;
    }

    /**
     * Create the tenant DB user (idempotent) and grant it full privileges on its
     * own database only. The password cannot be parameter-bound in DDL, so it is
     * escaped and inlined; identifiers are validated by the calling service.
     */
    private function provisionUser(string $driver, string $dbName, string $dbUser, string $dbPass, string $dbHost): void
    {
        $db = $this->central;

        if ($driver === 'pgsql') {
            $pass   = "'" . str_replace("'", "''", $dbPass) . "'";
            $exists = $db->queryOne('SELECT 1 AS present FROM pg_roles WHERE rolname = :u', ['u' => $dbUser]);
            if ($exists === null) {
                $db->execute("CREATE ROLE \"{$dbUser}\" LOGIN PASSWORD {$pass}");
            } else {
                $db->execute("ALTER ROLE \"{$dbUser}\" WITH LOGIN PASSWORD {$pass}");
            }
            $db->execute("GRANT ALL PRIVILEGES ON DATABASE \"{$dbName}\" TO \"{$dbUser}\"");
            $db->execute("ALTER DATABASE \"{$dbName}\" OWNER TO \"{$dbUser}\"");

            return;
        }

        if ($driver === 'sqlsrv') {
            $pass  = "'" . str_replace("'", "''", $dbPass) . "'";
            $login = str_replace(']', ']]', $dbUser);
            $db->execute(
                "IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = '{$dbUser}') "
                . "CREATE LOGIN [{$login}] WITH PASSWORD = {$pass}; "
                . "ELSE ALTER LOGIN [{$login}] WITH PASSWORD = {$pass};"
            );
            $db->execute(
                "USE [{$dbName}]; "
                . "IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = '{$dbUser}') "
                . "CREATE USER [{$login}] FOR LOGIN [{$login}]; "
                . "ALTER ROLE db_owner ADD MEMBER [{$login}];"
            );

            return;
        }

        // MySQL / MariaDB — pin the account to the connecting host (never '%').
        $pass = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $dbPass) . "'";
        foreach ($this->grantHosts($dbHost) as $host) {
            // CREATE USER IF NOT EXISTS is a no-op — password included — when the
            // account already exists, so ALTER USER forces the current credential
            // (a lingering account otherwise keeps a stale password → the tenant
            // connection fails "using password: YES").
            $db->execute("CREATE USER IF NOT EXISTS '{$dbUser}'@'{$host}' IDENTIFIED BY {$pass}");
            $db->execute("ALTER USER '{$dbUser}'@'{$host}' IDENTIFIED BY {$pass}");
            $db->execute("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'{$host}'");
        }
        $db->execute('FLUSH PRIVILEGES');
    }
}
