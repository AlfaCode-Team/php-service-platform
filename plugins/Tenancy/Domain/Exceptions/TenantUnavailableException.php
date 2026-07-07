<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a tenant cannot be routed to right now:
 *   - suspended / deleted (administrative)
 *   - database unreachable / circuit breaker open (operational)
 *
 * Carries an HTTP status so the pipeline maps it cleanly. Crucially it NEVER
 * falls back to another tenant or the central DB — fail closed.
 */
final class TenantUnavailableException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 503,
        public readonly string $reason = 'tenant_unavailable',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function suspended(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] is suspended.", 403, 'tenant_suspended');
    }

    public static function deleted(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] no longer exists.", 410, 'tenant_deleted');
    }

    public static function provisioning(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] is still provisioning.", 503, 'tenant_provisioning');
    }

    public static function breakerOpen(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] database is temporarily unreachable.", 503, 'tenant_db_down');
    }

    public static function connectFailed(string $tenantId, \Throwable $e): self
    {
        return new self("Failed to connect to tenant [{$tenantId}] database.", 503, 'tenant_db_down', $e);
    }
}
