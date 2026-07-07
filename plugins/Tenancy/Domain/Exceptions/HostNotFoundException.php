<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a host id/hostname does not belong to the acting tenant (or does
 * not exist). Maps to HTTP 404 — never leak whether a hostname is owned by
 * ANOTHER tenant.
 */
final class HostNotFoundException extends \RuntimeException
{
    public static function for(string $tenantId, string $ref): self
    {
        return new self("Host [{$ref}] was not found for tenant [{$tenantId}].");
    }
}
