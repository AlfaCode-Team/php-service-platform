<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

/**
 * TenantHostRegistryContract — read access to the central `tenant_hosts` table.
 *
 * Maps a hostname (domain or subdomain) to the tenant that owns it, so an
 * incoming request can be routed to a tenant BY ITS Host header alone. Only a
 * VERIFIED host resolves; pending/failed hosts are treated as unknown. Lookups
 * are cached (short TTL) so the central DB stays off the per-request hot path.
 */
interface TenantHostRegistryContract
{
    /**
     * Resolve the owning tenant_id for a hostname, or null when no verified host
     * matches. The hostname is matched case-insensitively, port/trailing-dot
     * stripped by the caller.
     */
    public function tenantForHost(string $hostname): ?string;

    /** Drop any cached copy of a hostname (call after host mutations). */
    public function forget(string $hostname): void;
}
