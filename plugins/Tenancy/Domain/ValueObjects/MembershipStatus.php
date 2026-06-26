<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

/**
 * MembershipStatus — state of a user's seat in a tenant (central `user_tenants`).
 *
 * Backed by the tinyint stored in `user_tenants.status`.
 */
enum MembershipStatus: int
{
    case Active    = 1;
    case Invited   = 2;
    case Suspended = 3;

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
