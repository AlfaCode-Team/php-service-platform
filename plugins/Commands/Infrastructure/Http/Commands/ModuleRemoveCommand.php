<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Http\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Commands\API\Contracts\ModuleManagementServiceContract;
use Plugins\Commands\API\DTOs\ModuleRemoveRequest;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * module:remove — thin wrapper that delegates to ModuleManagementService.
 *
 * 3-line rule:
 * 1. Build DTO from input
 * 2. Call service
 * 3. Render response or error
 */
final class ModuleRemoveCommand extends AbstractCommand
{
    public function __construct(
        private readonly ModuleManagementServiceContract $service,
    ) {}

    protected function configure(): void
    {
        $this->name        = 'module:remove';
        $this->description = 'Completely remove a git submodule and its Composer registration';
        $this->help        = <<<'HELP'
Removes a git submodule and cleans up:
  • .gitmodules entry
  • .git/config entry
  • modules/<name> directory
  • root composer.json references

Example:
  php cli module:remove payments
  php cli module:remove payments --force
HELP;

        $this->addArgument('name', 'Module name in kebab-case', required: true);
        $this->addOption('force', 'f', 'Skip confirmations');
    }

    protected function handle(): int
    {
        try {
            $request = ModuleRemoveRequest::fromInput($this);

            if (!$request->force) {
                $this->section('Module Removal Plan');
                $this->table()
                    ->headers(['Action'])
                    ->rows([
                        ['Remove: ' . $request->getModulePath()],
                        ['Clean: .gitmodules'],
                        ['Clean: .git/config'],
                        ['Clean: composer.json'],
                    ])
                    ->render();

                if (!$this->confirm('Proceed with removing this module? This cannot be undone.')) {
                    $this->muted('Aborted.');
                    return self::SUCCESS;
                }

                $this->newLine();
            }

            // Call service
            $response = $this->service->removeModule($request);

            if ($response->success) {
                $this->alertSuccess('Module Removed Successfully', [
                    "Module: {$response->moduleName}",
                ]);
                return self::SUCCESS;
            } else {
                $this->alertError('Failed to Remove Module', [$response->error ?? 'Unknown error']);
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
