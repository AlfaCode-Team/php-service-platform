<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Require an ACTIVE tenant context on the matched route (declarative filter
 * "tenant").
 *
 * TenantContextStage (after.load) sets the request 'tenant' attribute and
 * rebinds the tenant DatabasePort ONLY when a tenant was identified. A route
 * that reads/writes tenant-only tables therefore needs this guard: without it,
 * an authenticated-but-unscoped request (apex/central host, or a user who has
 * not selected a tenant) would fall through to the CENTRAL connection — where
 * the tenant tables do not exist — and fail with an opaque 500.
 *
 * Registered as a filter alias by the Tenancy Provider, so any tenant-scoped
 * plugin can opt a route in with "filters": ["auth", "tenant"]. Pair it with
 * "auth": in claim mode the tenant comes from the authenticated Identity, so the
 * caller must be authenticated first.
 */
final class RequireTenantStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $tenant = (string) ($request->attribute('tenant') ?? '');

        if ($tenant === '') {
            return Response::json([
                'error' => [
                    'code'    => 'tenant.required',
                    'message' => 'No active tenant. Select a tenant before accessing this resource.',
                ],
            ], 409);
        }

        return $next($request);
    }
}
