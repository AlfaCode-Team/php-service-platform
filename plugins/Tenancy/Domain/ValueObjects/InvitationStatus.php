<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

/**
 * InvitationStatus — lifecycle of a `tenant_invitations` row.
 *
 * Backed by the tinyint stored in `tenant_invitations.status`.
 */
enum InvitationStatus: int
{
    case Pending  = 1;
    case Accepted = 2;
    case Revoked  = 3;
    case Expired  = 4;

    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
