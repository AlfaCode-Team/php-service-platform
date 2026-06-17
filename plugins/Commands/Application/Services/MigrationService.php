<?php

declare(strict_types=1);

namespace Plugins\Commands\Application\Services;

use Plugins\Commands\API\Contracts\MigrationServiceContract;
use Plugins\Commands\API\DTOs\MigrateRequest;
use Plugins\Commands\API\DTOs\MigrateResponse;
use Plugins\Commands\API\DTOs\MigrateStatusRequest;
use Plugins\Commands\API\DTOs\MigrateStatusResponse;
use Plugins\Commands\Infrastructure\Persistence\MigrationRepository;
use Plugins\Commands\Logging\CommandExecutionLogger;
use Plugins\Commands\Deployment\DeploymentLockManager;
use Plugins\Commands\Backup\BackupManager;
use Plugins\Commands\Approval\MigrationApprovalManager;
use Plugins\Commands\Validation\PreFlightValidator;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * MigrationService — coordinates all migration operations with enterprise safeguards.
 *
 * Responsibilities:
 * 1. Load and validate configuration
 * 2. Acquire deployment locks (prevent concurrent runs)
 * 3. Run pre-flight validation (check system health)
 * 4. Create backups before destructive operations
 * 5. Check approval gates (production only)
 * 6. Execute migrations via repository
 * 7. Log all operations for audit trail
 * 8. Release locks
 *
 * This service is the ONLY place that orchestrates these features.
 * Commands call this service; never go directly to repository.
 */
final class MigrationService implements MigrationServiceContract
{
    public function __construct(
        private readonly MigrationRepository $repository,
        private readonly CommandExecutionLogger $logger,
        private readonly DeploymentLockManager $lockManager,
        private readonly BackupManager $backupManager,
        private readonly MigrationApprovalManager $approvalManager,
        private readonly PreFlightValidator $validator,
    ) {}

    /**
     * Run pending migrations with all enterprise safeguards.
     *
     * Flow:
     * 1. Log operation start
     * 2. Acquire lock
     * 3. Pre-flight validation
     * 4. Check approval (if required)
     * 5. Create backup (if required)
     * 6. Run migrations
     * 7. Log completion
     * 8. Release lock
     */
    public function runMigrations(MigrateRequest $request): MigrateResponse
    {
        $this->logger->logStart('migrate:run', [$request->configPath ?? 'default']);

        try {
            $this->lockManager->acquireLock();

            try {
                // Pre-flight validation
                $config = $this->repository->loadConfiguration($request->configPath);
                $report = $this->validator->validate($config);

                if ($report->hasErrors()) {
                    throw ServiceException::migrationFailed(
                        'Pre-flight validation failed: ' . implode(', ', $report->getErrors())
                    );
                }

                // Check approvals if required
                if ($config['require_approval'] ?? false) {
                    $this->approvalManager->checkApproval();
                }

                // Create backup if required
                if ($config['require_backup'] ?? false) {
                    $this->backupManager->createBackup($config);
                }

                // Run migrations
                $result = $this->repository->runPending($config);

                $this->logger->logMigration('migrate:run', 'up', true);
                $this->logger->logEnd(0);

                return MigrateResponse::success(count($result), [
                    'migrations' => $result,
                ]);
            } finally {
                $this->lockManager->releaseLock();
            }
        } catch (ServiceException $e) {
            $this->logger->logMigration('migrate:run', 'up', false, $e->getMessage());
            $this->logger->logEnd(1, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw ServiceException::migrationFailed($e->getMessage());
        }
    }

    /**
     * Rollback the last N migration batches.
     *
     * Similar to runMigrations but for rollback direction.
     */
    public function rollbackMigrations(MigrateRequest $request): MigrateResponse
    {
        $this->logger->logStart('migrate:rollback', [$request->steps]);

        try {
            $this->lockManager->acquireLock();

            try {
                $config = $this->repository->loadConfiguration($request->configPath);
                $report = $this->validator->validate($config);

                if ($report->hasErrors()) {
                    throw ServiceException::migrationFailed('Pre-flight validation failed');
                }

                // Backup before rollback
                if ($config['require_backup'] ?? false) {
                    $this->backupManager->createBackup($config);
                }

                $result = $this->repository->rollback($config, $request->steps);

                $this->logger->logMigration('migrate:rollback', 'down', true);
                $this->logger->logEnd(0);

                return MigrateResponse::success(count($result));
            } finally {
                $this->lockManager->releaseLock();
            }
        } catch (ServiceException $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw ServiceException::migrationFailed($e->getMessage());
        }
    }

    /**
     * Get current migration status (read-only, no locks needed).
     */
    public function getMigrationStatus(MigrateStatusRequest $request): MigrateStatusResponse
    {
        try {
            $config = $this->repository->loadConfiguration($request->configPath);
            $status = $this->repository->getStatus($config);

            return MigrateStatusResponse::fromMigrations(
                $status['applied'] ?? [],
                $status['pending'] ?? [],
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                'Failed to get migration status: ' . $e->getMessage()
            );
        }
    }

    /**
     * Reset all migrations (rollback everything).
     * This is destructive and requires lock + backup.
     */
    public function resetMigrations(MigrateRequest $request): MigrateResponse
    {
        $this->logger->logStart('migrate:reset', []);

        try {
            $this->lockManager->acquireLock();

            try {
                $config = $this->repository->loadConfiguration($request->configPath);

                // Always backup on reset
                $this->backupManager->createBackup($config);

                $result = $this->repository->reset($config);

                $this->logger->logDestructiveOperation(
                    'migrate:reset',
                    'Reset all migrations'
                );
                $this->logger->logEnd(0);

                return MigrateResponse::success(count($result));
            } finally {
                $this->lockManager->releaseLock();
            }
        } catch (ServiceException $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw ServiceException::migrationFailed($e->getMessage());
        }
    }

    /**
     * Refresh migrations (reset + re-run all).
     * Very destructive — requires lock + backup.
     */
    public function refreshMigrations(MigrateRequest $request): MigrateResponse
    {
        $this->logger->logStart('migrate:refresh', []);

        try {
            $this->lockManager->acquireLock();

            try {
                $config = $this->repository->loadConfiguration($request->configPath);

                // Always backup on refresh
                $this->backupManager->createBackup($config);

                $result = $this->repository->refresh($config);

                $this->logger->logDestructiveOperation(
                    'migrate:refresh',
                    'Refresh all migrations (reset + re-run)'
                );
                $this->logger->logEnd(0);

                return MigrateResponse::success(count($result));
            } finally {
                $this->lockManager->releaseLock();
            }
        } catch (ServiceException $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw ServiceException::migrationFailed($e->getMessage());
        }
    }
}
