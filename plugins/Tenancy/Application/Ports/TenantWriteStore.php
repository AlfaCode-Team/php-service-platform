<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantWriteStore — the persistence boundary for control-plane tenant CRUD.
 *
 * Implemented by an Infrastructure repository that talks to the CENTRAL
 * `tenants` registry through DatabasePort only. The Application service depends
 * on THIS interface, never on a connection or raw SQL — so the GDA rule
 * "Service → Repository, Repository → DatabasePort" holds.
 */
interface TenantWriteStore
{
    /** @return list<Tenant> Every tenant in the registry. */
    public function all(): array;

    /** One tenant by id, or null when it does not exist. */
    public function find(string $tenantId): ?Tenant;

    /** True when a tenant with this slug exists (optionally excluding one id). */
    public function slugExists(string $slug, ?string $exceptId = null): bool;

    /** Insert a new registry row (status carried on the entity). */
    public function insert(Tenant $tenant): void;

    /** Flip a provisioning tenant to active and stamp its schema version. */
    public function markActive(string $tenantId, int $schemaVersion): void;

    /**
     * Update safe metadata. Null values are left untouched.
     *
     * @param int|null $status backing TenantStatus value
     */
    public function updateMeta(string $tenantId, ?string $name, ?string $slug, ?int $status): void;

    /** Remove the registry row. */
    public function delete(string $tenantId): void;
}
