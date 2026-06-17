<?php

declare(strict_types=1);

namespace Plugins\Commands\Application\Services;

use Plugins\Commands\API\Contracts\ModuleManagementServiceContract;
use Plugins\Commands\API\DTOs\ModuleAddRequest;
use Plugins\Commands\API\DTOs\ModuleAddResponse;
use Plugins\Commands\API\DTOs\ModuleRemoveRequest;
use Plugins\Commands\API\DTOs\ModuleRemoveResponse;
use Plugins\Commands\Infrastructure\Persistence\ModuleRepository;
use Plugins\Commands\Logging\CommandExecutionLogger;
use Plugins\Commands\Deployment\DeploymentLockManager;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * ModuleManagementService — coordinates module add/remove with enterprise safeguards.
 *
 * This service is the ONLY place that:
 * 1. Acquires locks
 * 2. Logs operations
 * 3. Calls the repository
 * 4. Coordinates with enterprise features
 *
 * Commands are thin wrappers that only call this service.
 */
final class ModuleManagementService implements ModuleManagementServiceContract
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly CommandExecutionLogger $logger,
        private readonly DeploymentLockManager $lockManager,
    ) {}

    /**
     * Add a git submodule with enterprise safeguards.
     * Coordinates: lock → log → add → release lock
     */
    public function addModule(ModuleAddRequest $request): ModuleAddResponse
    {
        $this->logger->logStart('module:add', [$request->name, $request->gitUrl]);

        try {
            $this->lockManager->acquireLock();

            try {
                $response = $this->repository->add($request);
                $this->logger->logEnd(0);
                return $response;
            } finally {
                $this->lockManager->releaseLock();
            }
        } catch (ServiceException $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw ServiceException::moduleAddFailed($e->getMessage());
        }
    }

    /**
     * Remove a git submodule with enterprise safeguards.
     * Coordinates: lock → log → remove → release lock
     */
    public function removeModule(ModuleRemoveRequest $request): ModuleRemoveResponse
    {
        $this->logger->logStart('module:remove', [$request->name]);

        try {
            $this->lockManager->acquireLock();

            try {
                $response = $this->repository->remove($request);
                $this->logger->logEnd(0);
                return $response;
            } finally {
                $this->lockManager->releaseLock();
            }
        } catch (ServiceException $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw ServiceException::moduleRemoveFailed($e->getMessage());
        }
    }
}
