<?php

declare(strict_types=1);

namespace Plugins\Commands\Application\Services;

use Plugins\Commands\Infrastructure\Persistence\{
    DeploymentLockRepository,
    CommandAuditLogRepository,
    BackupRepository,
    ApprovalRepository,
    MigrationRepository,
    ModuleRepository,
};

/**
 * CommandsInfrastructureService — Single service aggregating all repositories.
 *
 * This service provides a unified interface for all infrastructure operations:
 * - Deployment locks (prevent concurrent migrations)
 * - Command audit logging (compliance trail)
 * - Migration approvals (authorization gates)
 * - Backup management (data safety)
 * - Pre-flight validation (system health)
 *
 * Instead of multiple utilities each injecting repositories,
 * infrastructure utilities and services inject THIS service.
 *
 * Architecture:
 *   DeploymentLockManager → CommandsInfrastructureService → DeploymentLockRepository → DatabasePort
 *   MigrationApprovalManager → CommandsInfrastructureService → ApprovalRepository → DatabasePort
 *   PreFlightValidator → CommandsInfrastructureService → MigrationRepository → DatabasePort
 *   etc.
 */
final class CommandsInfrastructureService
{
    public function __construct(
        private readonly DeploymentLockRepository $deploymentLocks,
        private readonly CommandAuditLogRepository $auditLogs,
        private readonly BackupRepository $backups,
        private readonly ApprovalRepository $approvals,
        private readonly MigrationRepository $migrations,
        private readonly ModuleRepository $modules,
    ) {}

    // ════════════════════════════════════════════════════════════════
    //  DEPLOYMENT LOCKS API
    // ════════════════════════════════════════════════════════════════

    public function isDeploymentLocked(string $lockKey): bool
    {
        return $this->deploymentLocks->isLocked($lockKey);
    }

    public function getDeploymentLockHolder(string $lockKey): ?string
    {
        return $this->deploymentLocks->getLockHolder($lockKey);
    }

    public function createDeploymentLock(string $lockKey, string $holder, string $expiresAt): void
    {
        $this->deploymentLocks->createLock($lockKey, $holder, $expiresAt);
    }

    public function deleteDeploymentLock(string $lockKey): void
    {
        $this->deploymentLocks->deleteLock($lockKey);
    }

    public function cleanupExpiredDeploymentLocks(): void
    {
        $this->deploymentLocks->cleanupExpiredLocks();
    }

    // ════════════════════════════════════════════════════════════════
    //  COMMAND AUDIT LOGGING API
    // ════════════════════════════════════════════════════════════════

    public function logCommandStart(
        string $command,
        string $user,
        string $hostname,
        int $pid,
        array $arguments,
    ): string {
        return $this->auditLogs->logStart($command, $user, $hostname, $pid, $arguments);
    }

    public function logCommandEnd(
        string $logId,
        int $exitCode,
        int $durationMs,
        ?string $errorMessage = null,
    ): void {
        $this->auditLogs->logEnd($logId, $exitCode, $durationMs, $errorMessage);
    }

    public function logMigrationOperation(
        string $logId,
        string $migrationName,
        string $direction,
        bool $success,
        ?string $errorMessage = null,
    ): void {
        $this->auditLogs->logMigration($logId, $migrationName, $direction, $success, $errorMessage);
    }

    public function logDestructiveCommandOperation(
        string $logId,
        string $operationName,
        string $details,
    ): void {
        $this->auditLogs->logDestructiveOperation($logId, $operationName, $details);
    }

    public function getRecentCommandLogs(int $limit = 20): array
    {
        return $this->auditLogs->getRecentLogs($limit);
    }

    // ════════════════════════════════════════════════════════════════
    //  BACKUP MANAGEMENT API
    // ════════════════════════════════════════════════════════════════

    public function recordBackup(
        string $database,
        string $backupPath,
        string $filename,
        int $fileSizeBytes,
    ): void {
        $this->backups->recordBackup($database, $backupPath, $filename, $fileSizeBytes);
    }

    public function listBackups(string $database, int $limit = 10): array
    {
        return $this->backups->listBackups($database, $limit);
    }

    public function getBackup(string $filename): ?array
    {
        return $this->backups->getBackup($filename);
    }

    public function deleteOldBackupRecords(int $daysOld = 30): int
    {
        return $this->backups->deleteOldBackupRecords($daysOld);
    }

    // ════════════════════════════════════════════════════════════════
    //  MIGRATION APPROVAL API
    // ════════════════════════════════════════════════════════════════

    public function createApprovalRequest(
        string $approvalId,
        array $migrations,
        string $requester,
    ): void {
        $this->approvals->createApprovalRequest($approvalId, $migrations, $requester);
    }

    public function getApprovalRequest(string $approvalId): ?array
    {
        return $this->approvals->getApprovalRequest($approvalId);
    }

    public function approveRequest(
        string $approvalId,
        string $approver,
        ?string $notes = null,
    ): void {
        $this->approvals->approve($approvalId, $approver, $notes);
    }

    public function rejectRequest(
        string $approvalId,
        string $rejector,
        string $reason,
    ): void {
        $this->approvals->reject($approvalId, $rejector, $reason);
    }

    public function getPendingApprovalRequests(): array
    {
        return $this->approvals->getPendingApprovals();
    }

    public function hasPendingApproval(int $timeoutSeconds = 3600): bool
    {
        return $this->approvals->hasPendingApproval($timeoutSeconds);
    }

    // ════════════════════════════════════════════════════════════════
    //  PRE-FLIGHT VALIDATION API
    // ════════════════════════════════════════════════════════════════

    public function isDatabaseAccessible(array $config): bool
    {
        return $this->migrations->isDatabaseAccessible($config);
    }

    public function doesTrackingTableExist(array $config): bool
    {
        return $this->migrations->doesTrackingTableExist($config);
    }

    public function loadMigrationConfiguration(?string $configPath = null): array
    {
        return $this->migrations->loadConfiguration($configPath);
    }

    // ════════════════════════════════════════════════════════════════
    //  MIGRATION OPERATIONS API
    // ════════════════════════════════════════════════════════════════

    public function getMigrationStatus(array $config): array
    {
        return $this->migrations->getStatus($config);
    }

    public function runPendingMigrations(array $config): array
    {
        return $this->migrations->runPending($config);
    }

    public function rollbackMigrations(array $config, int $steps = 1): array
    {
        return $this->migrations->rollback($config, $steps);
    }

    public function resetAllMigrations(array $config): array
    {
        return $this->migrations->reset($config);
    }

    public function refreshAllMigrations(array $config): array
    {
        return $this->migrations->refresh($config);
    }

    // ════════════════════════════════════════════════════════════════
    //  MODULE MANAGEMENT API
    // ════════════════════════════════════════════════════════════════

    public function addModule($request)
    {
        return $this->modules->add($request);
    }

    public function removeModule($request)
    {
        return $this->modules->remove($request);
    }

    // ════════════════════════════════════════════════════════════════
    //  DIRECT REPOSITORY ACCESS (for advanced use cases)
    // ════════════════════════════════════════════════════════════════

    public function deploymentLocks(): DeploymentLockRepository
    {
        return $this->deploymentLocks;
    }

    public function auditLogs(): CommandAuditLogRepository
    {
        return $this->auditLogs;
    }

    public function backups(): BackupRepository
    {
        return $this->backups;
    }

    public function approvals(): ApprovalRepository
    {
        return $this->approvals;
    }

    public function migrations(): MigrationRepository
    {
        return $this->migrations;
    }

    public function modules(): ModuleRepository
    {
        return $this->modules;
    }
}
