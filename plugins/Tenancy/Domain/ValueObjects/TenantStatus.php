<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

/**
 * TenantStatus — lifecycle state of a tenant in the central registry.
 *
 * Backed by the tinyint stored in the central `tenants.status` column so the
 * enum maps 1:1 onto persistence without a translation table.
 */
enum TenantStatus: int
{
    case Active       = 1;
    case Provisioning = 2;
    case Suspended    = 3;
    case Deleted      = 4;

    public function isRoutable(): bool
    {
        return $this === self::Active;
    }
}
