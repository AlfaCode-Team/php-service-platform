<?php

declare(strict_types=1);

namespace Plugins\Commands\Deployment;

use Plugins\Commands\Application\Services\CommandsInfrastructureService;

final class DeploymentLockManager
{
    private const LOCK_TIMEOUT_SECONDS = 300;
    private const LOCK_KEY = 'migration_deployment';
    private bool $locked = false;

    public function __construct(
        private readonly CommandsInfrastructureService $infrastructure,
    ) {}

    public function acquireLock(): void
    {
        // First check if lock already exists and is not expired
        if ($this->infrastructure->isDeploymentLocked(self::LOCK_KEY)) {
            $holder = $this->infrastructure->getDeploymentLockHolder(self::LOCK_KEY);
            throw DeploymentLockedException::alreadyLocked(self::LOCK_KEY, $holder ?? 'unknown');
        }

        // Clean up any expired locks
        $this->infrastructure->cleanupExpiredDeploymentLocks();

        // Try to acquire the lock
        $holder = $this->getHolderIdentity();
        $expiresAt = $this->getExpirationTime();

        try {
            $this->infrastructure->createDeploymentLock(self::LOCK_KEY, $holder, $expiresAt);
            $this->locked = true;
        } catch (\Exception) {
            throw DeploymentLockedException::acquireFailed(self::LOCK_KEY);
        }
    }

    public function releaseLock(): void
    {
        if (!$this->locked) {
            return;
        }

        try {
            $this->infrastructure->deleteDeploymentLock(self::LOCK_KEY);
            $this->locked = false;
        } catch (\Exception) {
            // Log but don't fail - lock will expire anyway
        }
    }

    public function isLocked(): bool
    {
        try {
            return $this->infrastructure->isDeploymentLocked(self::LOCK_KEY);
        } catch (\Exception) {
            return false;  // Table might not exist yet
        }
    }

    private function getHolderIdentity(): string
    {
        $host = gethostname();
        $pid = getmypid();
        $user = get_current_user();

        return "{$user}@{$host}:{$pid}";
    }

    private function getExpirationTime(): string
    {
        $timestamp = time() + self::LOCK_TIMEOUT_SECONDS;
        return date('Y-m-d H:i:s', $timestamp);
    }

    public function __destruct()
    {
        $this->releaseLock();
    }
}
