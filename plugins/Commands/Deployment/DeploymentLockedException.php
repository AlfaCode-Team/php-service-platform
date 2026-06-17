<?php

declare(strict_types=1);

namespace Plugins\Commands\Deployment;

final class DeploymentLockedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $lockKey = '',
        public readonly ?string $holder = null,
    ) {
        parent::__construct($message);
    }

    public static function alreadyLocked(string $lockKey, string $holder): self
    {
        return new self(
            "Deployment locked by {$holder}. Another deployment is in progress. Wait a few minutes and try again.",
            lockKey: $lockKey,
            holder: $holder,
        );
    }

    public static function acquireFailed(string $lockKey): self
    {
        return new self(
            "Failed to acquire deployment lock. Database error or lock already exists.",
            lockKey: $lockKey,
        );
    }
}
