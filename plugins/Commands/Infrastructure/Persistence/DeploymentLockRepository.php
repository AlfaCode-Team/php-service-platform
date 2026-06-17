<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * DeploymentLockRepository — handles all deployment lock database operations.
 *
 * Only this repository touches the database for deployment locks.
 * DeploymentLockManager NEVER touches DatabasePort directly.
 */
final class DeploymentLockRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /**
     * Check if a lock currently exists and is not expired.
     */
    public function isLocked(string $lockKey): bool
    {
        try {
            $lock = $this->db->queryOne(
                'SELECT * FROM deployment_locks WHERE lock_key = ? AND expires_at > NOW()',
                [$lockKey]
            );
            return $lock !== null;
        } catch (\Throwable $e) {
            throw ServiceException::lockAcquisitionFailed(
                "Failed to check lock status: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get the current lock holder identity (for error messages).
     */
    public function getLockHolder(string $lockKey): ?string
    {
        try {
            $lock = $this->db->queryOne(
                'SELECT holder FROM deployment_locks WHERE lock_key = ? AND expires_at > NOW()',
                [$lockKey]
            );
            return $lock['holder'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create a new deployment lock.
     *
     * @throws ServiceException
     */
    public function createLock(string $lockKey, string $holder, string $expiresAt): void
    {
        try {
            $this->db->execute(
                'INSERT INTO deployment_locks (lock_key, holder, expires_at) VALUES (?, ?, ?)',
                [$lockKey, $holder, $expiresAt]
            );
        } catch (\Throwable $e) {
            throw ServiceException::lockAcquisitionFailed($e->getMessage());
        }
    }

    /**
     * Delete a deployment lock.
     */
    public function deleteLock(string $lockKey): void
    {
        try {
            $this->db->execute(
                'DELETE FROM deployment_locks WHERE lock_key = ?',
                [$lockKey]
            );
        } catch (\Throwable $e) {
            throw ServiceException::lockAcquisitionFailed($e->getMessage());
        }
    }

    /**
     * Clean up expired locks.
     */
    public function cleanupExpiredLocks(): void
    {
        try {
            $this->db->execute(
                'DELETE FROM deployment_locks WHERE expires_at <= NOW()'
            );
        } catch (\Throwable $e) {
            // Ignore cleanup errors, just log them
            error_log("Failed to cleanup deployment locks: {$e->getMessage()}");
        }
    }
}
