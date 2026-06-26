<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Announced when a PREVIOUSLY-verified host loses its proof on re-verification
 * (DNS record pulled / domain transferred = revocation or takeover risk). The
 * host has been demoted to Failed and no longer routes. Primitives only.
 *
 * A TLS/ACME module subscribes to this to REVOKE/teardown the certificate and a
 * monitoring module to alert the tenant that their domain stopped resolving.
 */
final readonly class HostUnverifiedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $tenantId,
        public int $hostId,
        public string $hostname,
        public string $reason,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'tenant.host.unverified';
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
            'reason'     => $this->reason,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
