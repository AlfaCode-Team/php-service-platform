<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Identification;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Tenancy\API\Contracts\TenantHostRegistryContract;

/**
 * HostTenantIdentifier — the custom-domain model: the tenant is whichever one
 * OWNS the request Host, looked up in the central `tenant_hosts` registry.
 *
 *     acme.example.com   ->  tenant that registered & verified 'acme.example.com'
 *     shop.acme.io       ->  tenant that registered & verified 'shop.acme.io'
 *     unknown.host       ->  ''  (no verified host -> central; downstream 404s
 *                                  only if the route requires a tenant)
 *
 * Unlike {@see DomainTenantIdentifier}, this does NOT derive the id from the
 * host string — it maps the FULL hostname to a tenant_id through a verified row,
 * so tenants can bring their own apex/sub domains (not just labels under one
 * configured base domain). No auth is involved: it works for anonymous traffic.
 *
 * An unknown or unverified host yields '' — the request keeps the central
 * DatabasePort. The registry already restricts matches to status = verified.
 */
final class HostTenantIdentifier implements TenantIdentifier
{
    public function __construct(
        private readonly TenantHostRegistryContract $hosts,
    ) {}

    public function identify(Request $request): string
    {
        $host = $this->normaliseHost($request->host()); 
        if ($host === '') {
            return '';
        }

        return $this->hosts->tenantForHost($host) ?? '';
    }

    private function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host; // strip port
        return trim($host, '.');                             // strip trailing dot
    }
}
