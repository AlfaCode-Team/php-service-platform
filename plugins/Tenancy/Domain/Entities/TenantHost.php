<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\HostStatus;

/**
 * TenantHost — an immutable view of one row of the central `tenant_hosts` table.
 *
 * A host is a domain or subdomain that maps an incoming Host header to the
 * tenant that OWNS it. Ownership is proven out-of-band by publishing a DNS TXT
 * record carrying {@see $verificationToken}; until then the host stays Pending
 * and is NOT routable.
 *
 * Domain layer: zero external imports beyond Domain/.
 */
final readonly class TenantHost
{
    public function __construct(
        public int $hostId,
        public string $tenantId,
        public string $hostname,
        public ?string $ipAddress,
        public HostStatus $status,
        public string $verificationToken,
        public bool $isPrimary,
        public ?string $verifiedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * Hydrate from a central-DB row. Reconstitution only — records no events.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            hostId:            (int) ($row['host_id'] ?? 0),
            tenantId:          (string) $row['tenant_id'],
            hostname:          (string) $row['hostname'],
            ipAddress:         isset($row['ip_address']) ? (string) $row['ip_address'] : null,
            status:            HostStatus::from((int) ($row['status'] ?? 0)),
            verificationToken: (string) ($row['verification_token'] ?? ''),
            isPrimary:         (bool) ($row['is_primary'] ?? false),
            verifiedAt:        isset($row['verified_at']) ? (string) $row['verified_at'] : null,
            createdAt:         isset($row['created_at']) ? (string) $row['created_at'] : null,
            updatedAt:         isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        );
    }

    public function isVerified(): bool
    {
        return $this->status->isRoutable();
    }

    /**
     * Shape returned to the management UI. The verification token is safe to show
     * the OWNER — it is the public DNS challenge they must publish — but is never
     * a secret credential.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'host_id'            => $this->hostId,
            'tenant_id'          => $this->tenantId,
            'hostname'           => $this->hostname,
            'ip_address'         => $this->ipAddress,
            'status'             => $this->status->label(),
            'verification_token' => $this->verificationToken,
            'is_primary'         => $this->isPrimary,
            'verified_at'        => $this->verifiedAt,
            'created_at'         => $this->createdAt,
            'updated_at'         => $this->updatedAt,
        ];
    }
}
