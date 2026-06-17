<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Error;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{
    DomainException,
    GatewayException,
    KernelException,
    RepositoryException,
    SecurityException,
    ServiceException,
    ValidationException
};

/**
 * ErrorClassifier — maps a Throwable to a severity bucket.
 *
 * Severity drives which notifiers fire (see ErrorPipeline::rules()).
 *   critical → page someone (Repository, Gateway, Kernel, unknown)
 *   warning  → log + dashboard (Security, Service)
 *   info     → log only (Domain, Validation — expected business outcomes)
 */
final class ErrorClassifier
{
    public const CRITICAL = 'critical';
    public const WARNING  = 'warning';
    public const INFO     = 'info';

    public static function severityFor(\Throwable $e): string
    {
        return match (true) {
            $e instanceof DomainException,
            $e instanceof ValidationException     => self::INFO,

            $e instanceof SecurityException,
            $e instanceof ServiceException        => self::WARNING,

            $e instanceof RepositoryException,
            $e instanceof GatewayException,
            $e instanceof KernelException         => self::CRITICAL,

            default                               => self::CRITICAL,
        };
    }
}
