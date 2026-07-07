<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * TenantConnectionResolverContract — the single chokepoint that maps a
 * tenant_id to its isolated DatabasePort.
 *
 * Every read/write a request makes against tenant data flows through the
 * DatabasePort returned here. There is intentionally NO fallback: a missing,
 * suspended, or unreachable tenant throws — it must never resolve to another
 * tenant's database or to the central database.
 */
interface TenantConnectionResolverContract
{
    /**
     * Resolve (and lazily open) the database connection for a tenant.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\UnknownTenantException
     * @throws \Plugins\Tenancy\Domain\Exceptions\TenantUnavailableException
     */
    public function for(string $tenantId): DatabasePort;

    /**
     * Drop any open connection + cached registry row for a tenant so a
     * control-plane change (suspend / delete / credential rotation) takes effect
     * immediately, without waiting out the registry cache TTL.
     */
    public function invalidate(string $tenantId): void;
}
