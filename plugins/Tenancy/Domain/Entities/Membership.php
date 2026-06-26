<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\MembershipStatus;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

/**
 * Membership — an immutable view of one `user_tenants` row joined with the
 * tenant it grants access to. Carries everything the selection flow needs to
 * decide routing and mint a tenant-scoped token.
 *
 * Domain layer: zero external imports beyond Domain/.
 */
final readonly class Membership
{
    public function __construct(
        public string $userId,
        public string $tenantId,
        public string $tenantName,
        public string $tenantSlug,
        public string $role,
        public MembershipStatus $status,
        public TenantStatus $tenantStatus,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            userId:       (string) $row['user_id'],
            tenantId:     (string) $row['tenant_id'],
            tenantName:   (string) ($row['name'] ?? ''),
            tenantSlug:   (string) ($row['slug'] ?? ''),
            role:         (string) $row['role'],
            status:       MembershipStatus::from((int) $row['status']),
            tenantStatus: TenantStatus::from((int) ($row['tenant_status'] ?? TenantStatus::Active->value)),
        );
    }

    /** Routable only when BOTH the seat and the tenant are active. */
    public function isRoutable(): bool
    {
        return $this->status->isActive() && $this->tenantStatus->isRoutable();
    }
}
