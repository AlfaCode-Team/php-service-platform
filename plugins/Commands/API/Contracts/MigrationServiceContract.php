<?php

declare(strict_types=1);

namespace Plugins\Commands\API\Contracts;

use Plugins\Commands\API\DTOs\MigrateRequest;
use Plugins\Commands\API\DTOs\MigrateResponse;
use Plugins\Commands\API\DTOs\MigrateStatusRequest;
use Plugins\Commands\API\DTOs\MigrateStatusResponse;

interface MigrationServiceContract
{
    /**
     * Run pending migrations with enterprise safeguards.
     * Applies locks, backups, approval checks, and audit logging.
     *
     * @throws \Plugins\Commands\Exceptions\ServiceException
     */
    public function runMigrations(MigrateRequest $request): MigrateResponse;

    /**
     * Rollback the last N migration batches.
     *
     * @throws \Plugins\Commands\Exceptions\ServiceException
     */
    public function rollbackMigrations(MigrateRequest $request): MigrateResponse;

    /**
     * Get the current migration status without modifying anything.
     */
    public function getMigrationStatus(MigrateStatusRequest $request): MigrateStatusResponse;

    /**
     * Reset all migrations (roll back everything).
     * Requires explicit confirmation and locks.
     *
     * @throws \Plugins\Commands\Exceptions\ServiceException
     */
    public function resetMigrations(MigrateRequest $request): MigrateResponse;

    /**
     * Refresh migrations (reset + re-run all).
     * More aggressive than reset — use with caution.
     *
     * @throws \Plugins\Commands\Exceptions\ServiceException
     */
    public function refreshMigrations(MigrateRequest $request): MigrateResponse;
}
