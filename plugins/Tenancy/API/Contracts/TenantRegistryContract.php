<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantRegistryContract — read access to the central `tenants` registry.
 *
 * The registry is the source of truth that maps a tenant_id to its database
 * connection coordinates. Lookups are cached (short TTL) so they stay off the
 * per-request hot path; the central DB is only hit on a cache miss.
 */
interface TenantRegistryContract
{
    /** Resolve a tenant by its public id, or null when it does not exist. */
    public function find(string $tenantId): ?Tenant;

    /** True when an active, routable membership target exists for the id. */
    public function exists(string $tenantId): bool;

    /**
     * All tenants in a given lifecycle status — used by the fleet migrator and
     * provisioning tools. Not a hot path; not cached.
     *
     * @return list<Tenant>
     */
    public function listByStatus(int $status): array;

    /** Drop any cached copy of a tenant (call after registry mutations). */
    public function forget(string $tenantId): void;
}
