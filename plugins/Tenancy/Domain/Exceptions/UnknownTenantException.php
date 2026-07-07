<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/** Raised when a tenant_id has no row in the central registry. */
final class UnknownTenantException extends \RuntimeException
{
    public static function for(string $tenantId): self
    {
        return new self("Unknown tenant [{$tenantId}].");
    }
}
