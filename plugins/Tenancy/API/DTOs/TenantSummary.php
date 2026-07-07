<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

use Plugins\Tenancy\Domain\Entities\Membership;

/**
 * One entry in a user's tenant picker — the safe, outward-facing shape of a
 * membership. No connection coordinates ever cross this boundary.
 */
final readonly class TenantSummary
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $slug,
        public string $role,
        public string $status,
    ) {}

    public static function fromMembership(Membership $m): self
    {
        return new self(
            tenantId: $m->tenantId,
            name:     $m->tenantName,
            slug:     $m->tenantSlug,
            role:     $m->role,
            status:   strtolower($m->status->name),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenantId' => $this->tenantId,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'role'     => $this->role,
            'status'   => $this->status,
        ];
    }
}
