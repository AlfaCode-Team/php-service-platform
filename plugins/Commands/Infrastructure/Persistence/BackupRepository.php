<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * BackupRepository — handles database backup tracking and metadata.
 *
 * Note: Actual backup files are stored on filesystem (via BackupManager).
 * This repository only tracks backup metadata in the database.
 */
final class BackupRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /**
     * Record a backup operation in the database.
     *
     * @throws ServiceException
     */
    public function recordBackup(
        string $database,
        string $backupPath,
        string $filename,
        int $fileSizeBytes,
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO backups (database_name, backup_path, filename, file_size, created_at) VALUES (?, ?, ?, ?, NOW())',
                [$database, $backupPath, $filename, $fileSizeBytes]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to record backup: {$e->getMessage()}"
            );
        }
    }

    /**
     * List recent backups for a database.
     */
    public function listBackups(string $database, int $limit = 10): array
    {
        try {
            return $this->db->query(
                'SELECT * FROM backups WHERE database_name = ? ORDER BY created_at DESC LIMIT ?',
                [$database, $limit]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to list backups: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get a specific backup by filename.
     */
    public function getBackup(string $filename): ?array
    {
        try {
            return $this->db->queryOne(
                'SELECT * FROM backups WHERE filename = ?',
                [$filename]
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Delete old backup records (older than 30 days).
     */
    public function deleteOldBackupRecords(int $daysOld = 30): int
    {
        try {
            $this->db->execute(
                'DELETE FROM backups WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
                [$daysOld]
            );
            return 0; // Success
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to clean up backup records: {$e->getMessage()}"
            );
        }
    }
}
