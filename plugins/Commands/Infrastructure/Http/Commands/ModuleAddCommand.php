<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Http\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Commands\API\Contracts\ModuleManagementServiceContract;
use Plugins\Commands\API\DTOs\ModuleAddRequest;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * module:add — thin wrapper that delegates to ModuleManagementService.
 *
 * 3-line rule:
 * 1. Build DTO from input
 * 2. Call service
 * 3. Render response or error
 */
final class ModuleAddCommand extends AbstractCommand
{
    public function __construct(
        private readonly ModuleManagementServiceContract $service,
    ) {}

    protected function configure(): void
    {
        $this->name        = 'module:add';
        $this->description = 'Add a git submodule and register it as a Composer path package';
        $this->help        = <<<'HELP'
Clones the module as a git submodule under modules/<name>, bootstraps its
src/ directory and composer.json if absent, then patches the root composer.json
so Composer can resolve the package via a path repository.

Example:
  php cli module:add payments git@github.com:acme/payments.git acme
  php cli module:add payments git@github.com:acme/payments.git acme --offline
HELP;

        $this->addArgument('name',    'Module name in kebab-case (e.g. user-auth)',  required: true);
        $this->addArgument('git-url', 'Git repository URL (SSH or HTTPS)',           required: true);
        $this->addArgument('org',     'Composer vendor / GitHub org (e.g. acme)',    required: true);
        $this->addOption('offline', 'o', 'Install without network (COMPOSER_DISABLE_NETWORK=1)');
    }

    protected function handle(): int
    {
        try {
            $request = ModuleAddRequest::fromInput($this);

            // Summary table
            $this->section('Module Add Plan');
            $this->table()
                ->headers(['Field', 'Value'])
                ->style('compact')
                ->rows([
                    ['Module name',   $request->name],
                    ['Git URL',       $request->gitUrl],
                    ['Path',          $request->getModulePath()],
                    ['Package',       $request->getPackageName()],
                    ['Namespace',     $request->getNamespace()],
                    ['Composer mode', $request->offline ? 'offline (no network)' : 'online'],
                ])
                ->render();

            if (!$this->confirm('Proceed with adding this module?')) {
                $this->muted('Aborted.');
                return self::SUCCESS;
            }

            $this->newLine();

            // Call service
            $response = $this->service->addModule($request);

            if ($response->success) {
                $this->alertSuccess('Module Added Successfully', [
                    "Path:      {$response->modulePath}",
                    "Package:   {$response->packageName}",
                    "Namespace: {$response->namespace}",
                ]);
                return self::SUCCESS;
            } else {
                $this->alertError('Failed to Add Module', [$response->error ?? 'Unknown error']);
                return self::FAILURE;
            }
        } catch (ServiceException $e) {
            $this->alertError('Service Error', [$e->getMessage()]);
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->alertError('Unexpected Error', [$e->getMessage()]);
            return self::FAILURE;
        }
    }
}
