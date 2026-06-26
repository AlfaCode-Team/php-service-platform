<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Identification;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * TenantIdentifier — decides WHICH tenant a request belongs to.
 *
 * This is the pluggable seam that lets the Tenancy control plane route either
 * from the authenticated Identity (`Identity.tenantId`, the SaaS/JWT model) or
 * from the request Host sub-domain (the storefront/domain model), selected by
 * the TENANCY_MODE env. It returns ONLY the tenant id; the connection routing,
 * registry lookup, breaker, and fail-closed behaviour stay in
 * {@see \Plugins\Tenancy\Infrastructure\Http\Stages\TenantContextStage} +
 * {@see \Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract}.
 *
 * Returning '' means "no tenant" — the request keeps the central DatabasePort.
 */
interface TenantIdentifier
{
    /** Tenant id for this request, or '' for an unscoped/central request. */
    public function identify(Request $request): string;
}
