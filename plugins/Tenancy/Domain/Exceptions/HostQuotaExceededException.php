<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a tenant has reached its custom-domain quota. Anti-abuse guard so
 * a single tenant cannot register unbounded hosts (each costs a DNS scan and a
 * routing-cache slot). Maps to HTTP 422.
 */
final class HostQuotaExceededException extends \RuntimeException
{
    public function __construct(string $tenantId, int $max)
    {
        parent::__construct("Tenant [{$tenantId}] has reached its host limit of {$max}.");
    }
}
