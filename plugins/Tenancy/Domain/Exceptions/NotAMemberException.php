<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a user has no active membership for the tenant they tried to
 * select (no row, suspended seat, or the tenant itself is not routable).
 *
 * Maps to HTTP 403 — the caller is authenticated but not entitled to this tenant.
 */
final class NotAMemberException extends \RuntimeException
{
    public static function for(string $userId, string $tenantId): self
    {
        return new self("User [{$userId}] is not an active member of tenant [{$tenantId}].");
    }
}
