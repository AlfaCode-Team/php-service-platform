<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\API\DTOs\TenantDetail;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Plugins\Tenancy\Infrastructure\Cli\Concerns\ManagesTenantDatabase;
use Plugins\Tenancy\Support\Token;

/**
 * TenantAdminService — control-plane CRUD over the central `tenants` registry.
 *
 * The HTTP-facing twin of the tenant:create / tenant:delete CLI commands. All
 * reads/writes hit the CENTRAL connection (ConnectionManager default) — a
 * tenant DB is never touched here except to run its own template migrations.
 *
 * Identifiers are validated before they are ever concatenated into DDL; the
 * tenant DB password is stored ENCRYPTED and never read back out.
 */
final class TenantAdminService implements TenantAdminServiceContract
{
    use ManagesTenantDatabase;

    private const DRIVERS = ['mysql', 'pgsql', 'sqlsrv'];

    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
        private readonly EncryptionPort $crypto,
        private readonly TenantRegistryContract $registry,
        private readonly ?string $templatePath = null,
    ) {}

    public function list(): array
    {
        $rows = $this->central()->query(
            'SELECT * FROM tenants ORDER BY status ASC, name ASC'
        );

        return array_map(
            static fn (array $row): TenantDetail => TenantDetail::fromEntity(Tenant::fromRow($row)),
            $rows,
        );
    }

    public function get(string $tenantId): ?TenantDetail
    {
        $row = $this->central()->queryOne(
            'SELECT * FROM tenants WHERE tenant_id = :id',
            ['id' => $tenantId],
        );

        return $row === null ? null : TenantDetail::fromEntity(Tenant::fromRow($row));
    }

    public function create(array $input): TenantDetail
    {
        $name   = trim((string) ($input['name'] ?? ''));
        $slug   = strtolower(trim((string) ($input['slug'] ?? '')));
        $driver = strtolower(trim((string) ($input['driver'] ?? 'mysql')));
        $dbHost = trim((string) ($input['db_host'] ?? '127.0.0.1')) ?: '127.0.0.1';
        $dbPort = (int) ($input['db_port'] ?? 0) ?: $this->defaultPort($driver);
        $dbName = trim((string) ($input['db_name'] ?? ''));
        $dbUser = trim((string) ($input['db_user'] ?? ''));
        $dbPass = (string) ($input['db_password'] ?? '');

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'A name is required.';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            $errors['slug'] = 'Use ^[a-z0-9-]+$ only.';
        }
        if (!in_array($driver, self::DRIVERS, true)) {
            $errors['driver'] = "Use 'mysql', 'pgsql' or 'sqlsrv'.";
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
            $errors['db_name'] = 'Letters, digits and underscore only.';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbUser)) {
            $errors['db_user'] = 'Letters, digits and underscore only.';
        }
        if (!preg_match('/^[A-Za-z0-9_.:\-]+$/', $dbHost)) {
            $errors['db_host'] = 'Hostname/IP characters only.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $central = $this->central();
        if ($central->queryOne('SELECT 1 AS p FROM tenants WHERE slug = :s', ['s' => $slug]) !== null) {
            throw new ValidationException(['slug' => 'A tenant with this slug already exists.']);
        }

        $tenantId     = Token::ulid();
        $dbPreExisted = $this->databaseExists($central, $driver, $dbName);
        $dbCreated    = false;
        $userCreated  = false;

        try {
            // 1. Registry row (provisioning), password encrypted.
            $central->execute(
                'INSERT INTO tenants
                    (tenant_id, name, slug, db_driver, db_host, db_port, db_name,
                     db_username, db_password_enc, status, schema_version)
                 VALUES (:id, :name, :slug, :driver, :host, :port, :db, :user, :pass, :status, 0)',
                [
                    'id' => $tenantId, 'name' => $name, 'slug' => $slug,
                    'driver' => $driver, 'host' => $dbHost, 'port' => $dbPort,
                    'db' => $dbName, 'user' => $dbUser,
                    'pass' => $this->crypto->encryptString($dbPass),
                    'status' => TenantStatus::Provisioning->value,
                ],
            );

            // 2. Create the isolated database (idempotent, driver-aware).
            if (!$dbPreExisted) {
                match ($driver) {
                    'pgsql'  => $central->execute("CREATE DATABASE \"{$dbName}\""),
                    'sqlsrv' => $central->execute("IF DB_ID('{$dbName}') IS NULL CREATE DATABASE [{$dbName}]"),
                    default  => $central->execute("CREATE DATABASE IF NOT EXISTS `{$dbName}`"),
                };
                $dbCreated = true;
            }

            // 2b. Create the tenant DB user, granted on its database only.
            $this->provisionUser($central, $driver, $dbName, $dbUser, $dbPass, $dbHost);
            $userCreated = true;

            // 3. Run the tenant template migrations against the new database.
            $service = MigrationServiceFactory::fromConfig([
                'driver'        => $driver,
                'host'          => $dbHost,
                'port'          => $dbPort,
                'database'      => $dbName,
                'username'      => $dbUser,
                'password'      => $dbPass,
                'paths'         => [$this->resolveTemplatePath()],
                'transactional' => true,
            ]);
            $service->install();
            $service->run();

            // 4. Activate.
            $central->execute(
                'UPDATE tenants SET status = :s, schema_version = :v WHERE tenant_id = :id',
                ['s' => TenantStatus::Active->value, 'v' => 1, 'id' => $tenantId],
            );
        } catch (\Throwable $e) {
            $this->rollbackProvisioning($central, $driver, $dbName, $dbUser, $dbHost, $tenantId, $dbCreated, $userCreated);

            throw new ServiceException(
                'tenancy.create.failed',
                layer: 'service.tenancy.admin',
                context: ['slug' => $slug],
                previous: $e,
            );
        }

        $this->registry->forget($tenantId);

        return $this->get($tenantId) ?? throw new ServiceException(
            'tenancy.create.missing_after_commit',
            layer: 'service.tenancy.admin',
        );
    }

    public function update(string $tenantId, array $input): TenantDetail
    {
        $central = $this->central();
        if ($central->queryOne('SELECT 1 AS p FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]) === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }

        $sets   = [];
        $params = ['id' => $tenantId];
        $errors = [];

        if (array_key_exists('name', $input)) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                $errors['name'] = 'A name is required.';
            } else {
                $sets[] = 'name = :name';
                $params['name'] = $name;
            }
        }

        if (array_key_exists('slug', $input)) {
            $slug = strtolower(trim((string) $input['slug']));
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                $errors['slug'] = 'Use ^[a-z0-9-]+$ only.';
            } else {
                $clash = $central->queryOne(
                    'SELECT 1 AS p FROM tenants WHERE slug = :s AND tenant_id <> :id',
                    ['s' => $slug, 'id' => $tenantId],
                );
                if ($clash !== null) {
                    $errors['slug'] = 'Another tenant already uses this slug.';
                } else {
                    $sets[] = 'slug = :slug';
                    $params['slug'] = $slug;
                }
            }
        }

        if (array_key_exists('status', $input)) {
            $status = $this->statusFromName((string) $input['status']);
            if ($status === null) {
                $errors['status'] = 'Unknown status.';
            } else {
                $sets[] = 'status = :status';
                $params['status'] = $status->value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($sets === []) {
            return $this->get($tenantId);
        }

        try {
            $central->execute(
                'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE tenant_id = :id',
                $params,
            );
        } catch (\Throwable $e) {
            throw new ServiceException('tenancy.update.failed', layer: 'service.tenancy.admin', previous: $e);
        }

        $this->registry->forget($tenantId);

        return $this->get($tenantId);
    }

    public function delete(string $tenantId, bool $dropDatabase = false): void
    {
        $central = $this->central();
        $row     = $central->queryOne('SELECT * FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]);
        if ($row === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }
        $tenant = Tenant::fromRow($row);

        $failed = 0;
        try {
            $this->dropDatabaseUser($central, $tenant->dbDriver, $tenant->dbUsername, $tenant->dbHost);
        } catch (\Throwable) {
            $failed++;
        }

        if ($dropDatabase) {
            try {
                $this->dropDatabase($central, $tenant->dbDriver, $tenant->dbName);
            } catch (\Throwable) {
                $failed++;
            }
        }

        try {
            $central->execute('DELETE FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]);
        } catch (\Throwable $e) {
            throw new ServiceException('tenancy.delete.failed', layer: 'service.tenancy.admin', previous: $e);
        }

        $this->registry->forget($tenantId);

        if ($failed > 0) {
            throw new ServiceException(
                'tenancy.delete.partial',
                layer: 'service.tenancy.admin',
                context: ['tenantId' => $tenantId, 'failedSteps' => $failed],
            );
        }
    }

    /**
     * Create the tenant DB user (idempotent) and grant it full privileges on
     * its own database only. The password cannot be parameter-bound in DDL, so
     * it is escaped and inlined; identifiers are validated by create().
     */
    private function provisionUser(DatabasePort $db, string $driver, string $dbName, string $dbUser, string $dbPass, string $dbHost): void
    {
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
                . "CREATE LOGIN [{$login}] WITH PASSWORD = {$pass}"
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
            $db->execute("CREATE USER IF NOT EXISTS '{$dbUser}'@'{$host}' IDENTIFIED BY {$pass}");
            $db->execute("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'{$host}'");
        }
        $db->execute('FLUSH PRIVILEGES');
    }

    /** Compensating teardown — undo everything this run created, in reverse order. */
    private function rollbackProvisioning(
        DatabasePort $central,
        string $driver,
        string $dbName,
        string $dbUser,
        string $dbHost,
        string $tenantId,
        bool $dbCreated,
        bool $userCreated,
    ): void {
        if ($userCreated) {
            try {
                $this->dropDatabaseUser($central, $driver, $dbUser, $dbHost);
            } catch (\Throwable) {
            }
        }
        if ($dbCreated) {
            try {
                $this->dropDatabase($central, $driver, $dbName);
            } catch (\Throwable) {
            }
        }
        try {
            $central->execute('DELETE FROM tenants WHERE tenant_id = :id', ['id' => $tenantId]);
        } catch (\Throwable) {
        }
    }

    private function central(): DatabasePort
    {
        return $this->connections->default();
    }

    private function defaultPort(string $driver): int
    {
        return match ($driver) {
            'pgsql'  => 5432,
            'sqlsrv' => 1433,
            default  => 3306,
        };
    }

    private function statusFromName(string $name): ?TenantStatus
    {
        return match (strtolower(trim($name))) {
            'active'       => TenantStatus::Active,
            'provisioning' => TenantStatus::Provisioning,
            'suspended'    => TenantStatus::Suspended,
            'deleted'      => TenantStatus::Deleted,
            default        => null,
        };
    }

    private function resolveTemplatePath(): string
    {
        if (is_string($this->templatePath) && $this->templatePath !== '') {
            return $this->templatePath;
        }

        return dirname(__DIR__, 2) . '/database/tenant-template';
    }
}
