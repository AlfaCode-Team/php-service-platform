<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\TenantHost;

/**
 * Internal persistence port for the central `tenant_hosts` table (DIP seam — lets
 * TenantHostService be unit-tested without a database). Not part of the public
 * API; consumers use TenantHostServiceContract. All operations are tenant-scoped.
 */
interface TenantHostStore
{
    /** @return list<TenantHost> all hosts for a tenant (any status). */
    public function allForTenant(string $tenantId): array;

    /** A single host by id, scoped to the tenant, or null. */
    public function find(string $tenantId, int $hostId): ?TenantHost;

    /** True when the hostname is registered by ANY tenant (global uniqueness). */
    public function hostnameTaken(string $hostname): bool;

    /** Insert a Pending host; returns its new id. */
    public function insert(string $tenantId, string $hostname, ?string $ipAddress, string $verificationToken): int;

    /** Persist a verification outcome (status + verified_at). */
    public function markStatus(string $tenantId, int $hostId, int $status, ?string $verifiedAt): void;

    /** Make one host primary and demote every other host of the tenant. */
    public function setPrimary(string $tenantId, int $hostId): void;

    /** Soft-delete a host so it stops routing. */
    public function softDelete(string $tenantId, int $hostId): void;
}
