<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Persistence;

use Plugins\Commands\API\DTOs\ModuleAddRequest;
use Plugins\Commands\API\DTOs\ModuleAddResponse;
use Plugins\Commands\API\DTOs\ModuleRemoveRequest;
use Plugins\Commands\API\DTOs\ModuleRemoveResponse;
use Plugins\Commands\Infrastructure\Gateways\ShellGateway;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * ModuleRepository — handles all git submodule operations.
 * Only this repository interacts with ShellGateway for module management.
 */
final class ModuleRepository
{
    public function __construct(
        private readonly ShellGateway $shell,
        private readonly string $projectRoot,
    ) {}

    /**
     * Add a new git submodule and register it in Composer.
     *
     * @throws ServiceException
     */
    public function add(ModuleAddRequest $request): ModuleAddResponse
    {
        $modulePath = $this->projectRoot . '/' . $request->getModulePath();

        // 1. Check module doesn't already exist
        if ($this->moduleExists($modulePath)) {
            throw ServiceException::moduleAddFailed(
                "Module already exists at {$request->getModulePath()}"
            );
        }

        try {
            // 2. Clone as submodule
            $this->shell->git(
                "submodule add {$request->gitUrl} {$request->getModulePath()}"
            );

            // 3. Initialize submodule
            $this->shell->git('submodule update --init --recursive');

            // 4. Scaffold src/ directory if missing
            $srcPath = $modulePath . '/src';
            if (!$this->shell->directoryExists($srcPath)) {
                $this->shell->ensureDirectory($srcPath);
            }

            // 5. Create composer.json if missing
            $composerPath = $modulePath . '/composer.json';
            if (!$this->shell->fileExists($composerPath)) {
                $this->createComposerJson($composerPath, $request);
            }

            // 6. Update root composer.json
            $this->updateRootComposerJson($request);

            // 7. Run composer update
            $this->runComposerUpdate($request->offline);

            return ModuleAddResponse::success(
                $request->getModulePath(),
                $request->getPackageName(),
                $request->getNamespace(),
            );
        } catch (ServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ServiceException::moduleAddFailed($e->getMessage());
        }
    }

    /**
     * Remove a git submodule and clean up Composer.
     *
     * @throws ServiceException
     */
    public function remove(ModuleRemoveRequest $request): ModuleRemoveResponse
    {
        $modulePath = $this->projectRoot . '/' . $request->getModulePath();

        // 1. Check module exists
        if (!$this->moduleExists($modulePath)) {
            throw ServiceException::moduleRemoveFailed(
                "Module not found at {$request->getModulePath()}"
            );
        }

        try {
            // 2. Remove from .gitmodules
            $this->shell->git("config --file=.gitmodules --remove-section submodule.{$request->name}");

            // 3. Remove from .git/config
            $this->shell->git("config --remove-section submodule.{$request->name}");

            // 4. Remove the submodule directory
            $this->shell->git("rm -f {$request->getModulePath()}");

            // 5. Clean .gitmodules if empty
            if (!$this->gitmodulesHasContent()) {
                $this->shell->execute("rm -f {$this->projectRoot}/.gitmodules");
            }

            // 6. Remove from root composer.json
            $this->removeFromRootComposerJson($request->name);

            // 7. Run composer update
            $this->shell->execute('composer update', context: 'composer');

            return ModuleRemoveResponse::success($request->name);
        } catch (ServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ServiceException::moduleRemoveFailed($e->getMessage());
        }
    }

    /**
     * Check if a module exists.
     */
    private function moduleExists(string $modulePath): bool
    {
        return $this->shell->directoryExists($modulePath);
    }

    /**
     * Create a basic composer.json for the module.
     */
    private function createComposerJson(string $path, ModuleAddRequest $request): void
    {
        $json = [
            'name' => $request->getPackageName(),
            'type' => 'library',
            'description' => "Module: {$request->name}",
            'autoload' => [
                'psr-4' => [
                    $request->getNamespace() => 'src/',
                ],
            ],
        ];

        $content = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        $this->shell->writeFile($path, $content);
    }

    /**
     * Add the module to root composer.json.
     */
    private function updateRootComposerJson(ModuleAddRequest $request): void
    {
        $composerPath = $this->projectRoot . '/composer.json';
        $content = $this->shell->readFile($composerPath);
        $composer = json_decode($content, true);

        // Add path repository
        if (!isset($composer['repositories'])) {
            $composer['repositories'] = [];
        }

        $composer['repositories'][] = [
            'type' => 'path',
            'url' => $request->getModulePath(),
        ];

        // Add require entry
        if (!isset($composer['require'])) {
            $composer['require'] = [];
        }

        $composer['require'][$request->getPackageName()] = '*';

        $updated = json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        $this->shell->writeFile($composerPath, $updated);
    }

    /**
     * Remove module from root composer.json.
     */
    private function removeFromRootComposerJson(string $moduleName): void
    {
        $composerPath = $this->projectRoot . '/composer.json';
        $content = $this->shell->readFile($composerPath);
        $composer = json_decode($content, true);

        // Remove repository
        if (isset($composer['repositories'])) {
            $composer['repositories'] = array_filter(
                $composer['repositories'],
                fn($repo) => !isset($repo['url']) || !str_contains($repo['url'], "modules/{$moduleName}")
            );
        }

        // Remove require
        if (isset($composer['require'])) {
            foreach ($composer['require'] as $pkg => $ver) {
                if (str_contains($pkg, $moduleName)) {
                    unset($composer['require'][$pkg]);
                }
            }
        }

        $updated = json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        $this->shell->writeFile($composerPath, $updated);
    }

    /**
     * Check if .gitmodules has content.
     */
    private function gitmodulesHasContent(): bool
    {
        $path = $this->projectRoot . '/.gitmodules';
        if (!$this->shell->fileExists($path)) {
            return false;
        }

        $content = $this->shell->readFile($path);
        return !empty(trim($content));
    }

    /**
     * Run composer update.
     */
    private function runComposerUpdate(bool $offline): void
    {
        $cmd = $offline
            ? 'COMPOSER_DISABLE_NETWORK=1 composer update'
            : 'composer update';

        $result = $this->shell->execute($cmd, context: 'composer');
        if (!$result->ok()) {
            throw new ServiceException("Composer update failed: {$result->output()}");
        }
    }
}
