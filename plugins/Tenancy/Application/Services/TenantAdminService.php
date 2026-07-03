<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\API\DTOs\TenantDetail;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Application\Ports\TenantWriteStore;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Plugins\Tenancy\Support\Token;

/**
 * TenantAdminService — control-plane CRUD over the central `tenants` registry.
 *
 * Pure orchestration: it validates input, then drives the persistence boundary
 * ({@see TenantWriteStore}) and the data-plane boundary ({@see TenantProvisioner}).
 * It holds no connection, writes no SQL and runs no DDL — those live behind the
 * ports, honouring the GDA rule "Service → Repository/Gateway; Repository →
 * DatabasePort only".
 */
final class TenantAdminService implements TenantAdminServiceContract
{
    private const DRIVERS = ['mysql', 'pgsql', 'sqlsrv'];

    /** Permission (or role) a caller must hold to manage the tenant fleet. */
    private const ADMIN_PERMISSION = 'tenancy:admin';
    private const ADMIN_ROLE       = 'platform-admin';

    public function __construct(
        private readonly TenantWriteStore $store,
        private readonly TenantProvisioner $provisioner,
        private readonly TenantRegistryContract $registry,
        private readonly EncryptionPort $crypto,
        private readonly Identity $identity,
    ) {}

    public function list(): array
    {
        $this->requireAdmin();

        return array_map(
            static fn (Tenant $t): TenantDetail => TenantDetail::fromEntity($t),
            $this->store->all(),
        );
    }

    public function get(string $tenantId): ?TenantDetail
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);

        return $tenant === null ? null : TenantDetail::fromEntity($tenant);
    }

    public function create(array $input): TenantDetail
    {
        $this->requireAdmin();

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
        if ($this->store->slugExists($slug)) {
            throw new ValidationException(['slug' => 'A tenant with this slug already exists.']);
        }

        // Provisioning entity — password stored ENCRYPTED, status=provisioning.
        $tenant = Tenant::create(
            tenantId:      Token::ulid(),
            name:          $name,
            slug:          $slug,
            dbDriver:      $driver,
            dbHost:        $dbHost,
            dbPort:        $dbPort,
            dbName:        $dbName,
            dbUsername:    $dbUser,
            dbPasswordEnc: $this->crypto->encryptString($dbPass),
            status:        TenantStatus::Provisioning,
            schemaVersion: 0,
        );

        $dbPreExisted = $this->provisioner->databaseExists($tenant);

        try {
            $this->store->insert($tenant);
            $this->provisioner->provision($tenant, $dbPass, $dbPreExisted);
            $this->store->markActive($tenant->tenantId, schemaVersion: 1);
        } catch (\Throwable $e) {
            // Compensate: undo infra we created, then the registry row.
            $this->provisioner->teardown($tenant, dropDatabase: !$dbPreExisted);
            try {
                $this->store->delete($tenant->tenantId);
            } catch (\Throwable) {
            }

            throw new ServiceException(
                'tenancy.create.failed',
                layer: 'service.tenancy.admin',
                context: ['slug' => $slug],
                previous: $e,
            );
        }

        $this->registry->forget($tenant->tenantId);

        return $this->get($tenant->tenantId) ?? throw new ServiceException(
            'tenancy.create.missing_after_commit',
            layer: 'service.tenancy.admin',
        );
    }

    public function update(string $tenantId, array $input): TenantDetail
    {
        $this->requireAdmin();

        if ($this->store->find($tenantId) === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }

        $name   = null;
        $slug   = null;
        $status = null;
        $errors = [];

        if (array_key_exists('name', $input)) {
            $candidate = trim((string) $input['name']);
            if ($candidate === '') {
                $errors['name'] = 'A name is required.';
            } else {
                $name = $candidate;
            }
        }

        if (array_key_exists('slug', $input)) {
            $candidate = strtolower(trim((string) $input['slug']));
            if (!preg_match('/^[a-z0-9-]+$/', $candidate)) {
                $errors['slug'] = 'Use ^[a-z0-9-]+$ only.';
            } elseif ($this->store->slugExists($candidate, exceptId: $tenantId)) {
                $errors['slug'] = 'Another tenant already uses this slug.';
            } else {
                $slug = $candidate;
            }
        }

        if (array_key_exists('status', $input)) {
            $resolved = $this->statusFromName((string) $input['status']);
            if ($resolved === null) {
                $errors['status'] = 'Unknown status.';
            } else {
                $status = $resolved->value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->store->updateMeta($tenantId, $name, $slug, $status);
        $this->registry->forget($tenantId);

        return $this->get($tenantId) ?? throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
    }

    public function delete(string $tenantId, bool $dropDatabase = false): void
    {
        $this->requireAdmin();

        $tenant = $this->store->find($tenantId);
        if ($tenant === null) {
            throw new ServiceException('tenancy.not_found', layer: 'service.tenancy.admin', context: ['id' => $tenantId]);
        }

        $failed = $this->provisioner->teardown($tenant, dropDatabase: $dropDatabase);
        $this->store->delete($tenantId);
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
     * Control-plane authorization. Tenant management provisions real databases
     * and mutates the central registry, so it is restricted to platform admins.
     * Enforced HERE (not only in the controller) so any caller of the published
     * contract is gated — the HTTP guard is just a cheap early reject.
     */
    private function requireAdmin(): void
    {
        if ($this->identity->isGuest()
            || (!$this->identity->hasPermission(self::ADMIN_PERMISSION)
                && !$this->identity->hasRole(self::ADMIN_ROLE))) {
            throw new SecurityException(
                'tenancy.admin.forbidden',
                layer: 'service.tenancy.admin',
            );
        }
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
}
