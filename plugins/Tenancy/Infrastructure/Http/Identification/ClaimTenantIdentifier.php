<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Identification;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * ClaimTenantIdentifier — the default (SaaS) model: the tenant is the verified
 * `tnt` claim on the authenticated Identity, set by the Auth security layer.
 *
 * A guest / unauthenticated request, or an Identity with an empty tenantId,
 * yields '' — the request stays on the central connection (login, tenant
 * picker, public pages). Tenant-scoped routes must require auth themselves.
 */
final class ClaimTenantIdentifier implements TenantIdentifier
{
    public function identify(Request $request): string
    {
        return $request->identity()?->tenantId ?? '';
    }
}
