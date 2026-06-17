<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * CommandAuditLogRepository — handles command audit log database operations.
 *
 * Only this repository touches the database for audit logs.
 * CommandExecutionLogger NEVER touches DatabasePort directly.
 */
final class CommandAuditLogRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /**
     * Insert a command execution start log.
     *
     * @throws ServiceException
     */
    public function logStart(
        string $command,
        string $user,
        string $hostname,
        int $pid,
        array $arguments,
    ): string {
        try {
            $this->db->execute(
                'INSERT INTO command_audit_logs (command, user, hostname, pid, arguments, executed_at) VALUES (?, ?, ?, ?, ?, NOW())',
                [
                    $command,
                    $user,
                    $hostname,
                    $pid,
                    json_encode($arguments),
                ]
            );
            return $this->db->lastInsertId();
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to log command: {$e->getMessage()}"
            );
        }
    }

    /**
     * Update command execution end log (exit code, duration, error message).
     *
     * @throws ServiceException
     */
    public function logEnd(
        string $logId,
        int $exitCode,
        int $durationMs,
        ?string $errorMessage = null,
    ): void {
        try {
            $this->db->execute(
                'UPDATE command_audit_logs SET exit_code = ?, duration_ms = ?, error_message = ? WHERE id = ?',
                [$exitCode, $durationMs, $errorMessage, $logId]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to update command log: {$e->getMessage()}"
            );
        }
    }

    /**
     * Log a migration operation.
     *
     * @throws ServiceException
     */
    public function logMigration(
        string $logId,
        string $migrationName,
        string $direction,
        bool $success,
        ?string $errorMessage = null,
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO command_audit_logs (parent_id, migration_name, direction, success, error_message) VALUES (?, ?, ?, ?, ?)',
                [$logId, $migrationName, $direction, $success ? 1 : 0, $errorMessage]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to log migration: {$e->getMessage()}"
            );
        }
    }

    /**
     * Log a destructive operation.
     *
     * @throws ServiceException
     */
    public function logDestructiveOperation(
        string $logId,
        string $operationName,
        string $details,
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO command_audit_logs (parent_id, destructive_op, operation_details) VALUES (?, ?, ?)',
                [$logId, $operationName, $details]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to log destructive operation: {$e->getMessage()}"
            );
        }
    }

    /**
     * Query recent logs.
     */
    public function getRecentLogs(int $limit = 20): array
    {
        try {
            return $this->db->query(
                'SELECT * FROM command_audit_logs WHERE parent_id IS NULL ORDER BY executed_at DESC LIMIT ?',
                [$limit]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to query audit logs: {$e->getMessage()}"
            );
        }
    }
}
