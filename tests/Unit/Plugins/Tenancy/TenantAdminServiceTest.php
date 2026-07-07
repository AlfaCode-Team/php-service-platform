<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Tenancy;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Application\Ports\TenantProvisioner;
use Plugins\Tenancy\Application\Ports\TenantWriteStore;
use Plugins\Tenancy\Application\Services\TenantAdminService;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

#[CoversClass(TenantAdminService::class)]
final class TenantAdminServiceTest extends TestCase
{
    private FakeTenantWriteStore $store;
    private FakeTenantProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->store       = new FakeTenantWriteStore();
        $this->provisioner = new FakeTenantProvisioner();
    }

    private function service(Identity $identity): TenantAdminService
    {
        return new TenantAdminService(
            store:       $this->store,
            provisioner: $this->provisioner,
            registry:    new FakeTenantRegistry(),
            crypto:      new FakeCrypto(),
            identity:    $identity,
        );
    }

    private function admin(): Identity
    {
        return new Identity('admin-1', '', ['platform-admin'], ['tenancy:admin'], 'jwt');
    }

    private function member(): Identity
    {
        return new Identity('user-1', 'tenant-1', ['member'], [], 'jwt');
    }

    private function validInput(): array
    {
        return [
            'name' => 'Acme', 'slug' => 'acme', 'driver' => 'mysql',
            'db_name' => 'acme_db', 'db_user' => 'acme_user', 'db_password' => 'secret',
        ];
    }

    // ── authorization ───────────────────────────────────────────────────────

    public function test_guest_cannot_list(): void
    {
        $this->expectException(SecurityException::class);
        $this->service(Identity::guest())->list();
    }

    public function test_member_cannot_create(): void
    {
        $this->expectException(SecurityException::class);
        $this->service($this->member())->create($this->validInput());
    }

    public function test_member_cannot_delete(): void
    {
        $this->expectException(SecurityException::class);
        $this->service($this->member())->delete('any');
    }

    // ── provisioning (admin) ──────────────────────────────────────────────────

    public function test_admin_creates_and_provisions_tenant(): void
    {
        $detail = $this->service($this->admin())->create($this->validInput());

        $this->assertSame('acme', $detail->slug);
        $this->assertTrue($this->provisioner->provisioned, 'provision() should be called');
        $this->assertNotNull($this->store->find($detail->tenantId));
        $this->assertSame(TenantStatus::Active->value, $this->store->find($detail->tenantId)->status->value);
    }

    public function test_invalid_slug_is_rejected_before_provisioning(): void
    {
        try {
            $this->service($this->admin())->create(['name' => 'Acme', 'slug' => 'Bad Slug!', 'db_name' => 'd', 'db_user' => 'u']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors);
            $this->assertFalse($this->provisioner->provisioned, 'must not provision on invalid input');
        }
    }

    public function test_provision_failure_compensates_and_throws(): void
    {
        $this->provisioner->failOnProvision = true;

        try {
            $this->service($this->admin())->create($this->validInput());
            $this->fail('Expected ServiceException');
        } catch (ServiceException $e) {
            $this->assertTrue($this->provisioner->toreDown, 'teardown() should compensate');
            $this->assertSame([], $this->store->rows, 'registry row should be rolled back');
        }
    }
}

// ── in-memory fakes ───────────────────────────────────────────────────────────

final class FakeTenantWriteStore implements TenantWriteStore
{
    /** @var array<string, Tenant> */
    public array $rows = [];

    public function all(): array { return array_values($this->rows); }

    public function find(string $tenantId): ?Tenant
    {
        return $this->rows[$tenantId] ?? null;
    }

    public function slugExists(string $slug, ?string $exceptId = null): bool
    {
        foreach ($this->rows as $t) {
            if ($t->slug === $slug && $t->tenantId !== $exceptId) {
                return true;
            }
        }
        return false;
    }

    public function insert(Tenant $tenant): void { $this->rows[$tenant->tenantId] = $tenant; }

    public function markActive(string $tenantId, int $schemaVersion): void
    {
        $this->rows[$tenantId] = $this->withStatus($this->rows[$tenantId], TenantStatus::Active, $schemaVersion);
    }

    public function updateMeta(string $tenantId, ?string $name, ?string $slug, ?int $status): void {}

    public function delete(string $tenantId): void { unset($this->rows[$tenantId]); }

    private function withStatus(Tenant $t, TenantStatus $status, int $schemaVersion): Tenant
    {
        return Tenant::create(
            tenantId: $t->tenantId, name: $t->name, slug: $t->slug, dbDriver: $t->dbDriver,
            dbHost: $t->dbHost, dbPort: $t->dbPort, dbName: $t->dbName, dbUsername: $t->dbUsername,
            dbPasswordEnc: $t->dbPasswordEnc, status: $status, schemaVersion: $schemaVersion,
        );
    }
}

final class FakeTenantProvisioner implements TenantProvisioner
{
    public bool $provisioned = false;
    public bool $toreDown = false;
    public bool $failOnProvision = false;

    public function databaseExists(Tenant $tenant): bool { return false; }

    public function provision(Tenant $tenant, string $plainPassword, bool $databaseAlreadyExists): void
    {
        if ($this->failOnProvision) {
            throw new \RuntimeException('provision boom');
        }
        $this->provisioned = true;
    }

    public function teardown(Tenant $tenant, bool $dropDatabase): int
    {
        $this->toreDown = true;
        return 0;
    }
}

final class FakeTenantRegistry implements TenantRegistryContract
{
    public function find(string $tenantId): ?Tenant { return null; }
    public function exists(string $tenantId): bool { return false; }
    public function listByStatus(int $status): array { return []; }
    public function forget(string $tenantId): void {}
}

final class FakeCrypto implements EncryptionPort
{
    public function encrypt(mixed $value, bool $serialize = true): string { return 'enc'; }
    public function decrypt(string $payload, bool $unserialize = true): mixed { return ''; }
    public function encryptString(string $value): string { return 'enc:' . $value; }
    public function decryptString(string $payload): string { return substr($payload, 4); }
}
