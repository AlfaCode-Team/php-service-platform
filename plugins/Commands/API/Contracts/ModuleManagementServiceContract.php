<?php

declare(strict_types=1);

namespace Plugins\Commands\API\Contracts;

use Plugins\Commands\API\DTOs\ModuleAddRequest;
use Plugins\Commands\API\DTOs\ModuleAddResponse;
use Plugins\Commands\API\DTOs\ModuleRemoveRequest;
use Plugins\Commands\API\DTOs\ModuleRemoveResponse;

interface ModuleManagementServiceContract
{
    /**
     * Add a git submodule and register it as a Composer path package.
     * Coordinates with git, composer, and file system.
     *
     * @throws \Plugins\Commands\Exceptions\ServiceException
     */
    public function addModule(ModuleAddRequest $request): ModuleAddResponse;

    /**
     * Remove a git submodule and clean up Composer registration.
     *
     * @throws \Plugins\Commands\Exceptions\ServiceException
     */
    public function removeModule(ModuleRemoveRequest $request): ModuleRemoveResponse;
}
