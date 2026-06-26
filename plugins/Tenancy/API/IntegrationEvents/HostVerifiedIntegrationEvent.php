<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Announced when a tenant host PROVES ownership (DNS verified). Primitives only.
 *
 * The hook a TLS/ACME module subscribes to in order to auto-issue a certificate
 * for the now-trusted domain, or that a provisioning pipeline uses to flip the
 * domain live. Carries no secrets — the hostname is public.
 */
final readonly class HostVerifiedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $tenantId,
        public int $hostId,
        public string $hostname,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'tenant.host.verified';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'tenantId'   => $this->tenantId,
            'hostId'     => $this->hostId,
            'hostname'   => $this->hostname,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
