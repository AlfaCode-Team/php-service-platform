<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

/**
 * Internal persistence port for creating/activating seats in central
 * `user_tenants` (DIP seam). Separate from MembershipReader so read-only
 * consumers (the selection flow) never gain write access.
 */
interface MembershipWriter
{
    /**
     * Add a membership, or re-activate + update the role of an existing one
     * (idempotent — accepting an invite for an existing seat must not fail).
     */
    public function upsertActive(string $userId, string $tenantId, string $role): void;
}
