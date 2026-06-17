<?php

declare(strict_types=1);

namespace Plugins\View;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\View\API\Contracts\ViewRendererContract;
use Plugins\View\Infrastructure\PhpViewRenderer;

/**
 * View plugin — PHP template rendering for HTML routes.
 *
 * Exposes ViewRendererContract, a request-scoped renderer derived from the
 * legacy CodeIgniter View engine but rebuilt to the platform rules: every
 * dependency (view paths, extensions, decorators, escaper) is injected from
 * config here — the engine itself reads NO globals.
 *
 * ACTIVATION: on-demand. A module whose routes render views declares it:
 *   { "requires": ["view.rendering"] }
 *
 * Config (all optional):
 *   VIEW_PATHS      colon/comma-separated absolute view directories
 *   VIEW_EXTENSIONS comma-separated recognised extensions (default "php")
 *   VIEW_SAVE_DATA  persist set data across render() calls (default false)
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'view.rendering';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [ViewRendererContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(ViewRendererContract::class)) {
            return; // a project already provided a renderer
        }

        $container->bind(ViewRendererContract::class, static function (): PhpViewRenderer {
            $paths = self::splitList(env('VIEW_PATHS') ?: '');

            if ($paths === []) {
                $paths = ['resources/views'];
            }

            $paths = array_values(array_filter(array_map(function ($path) {

                // Skip if already absolute
                if (str_starts_with($path, '/') || preg_match('/^[A-Z]:\\\\/i', $path)) {
                    return is_dir($path) ? $path : null;
                }

                // Convert to project path
                $projectPath = Paths::project($path);

                return is_dir($projectPath) ? $projectPath : null;

            }, $paths)));




            return new PhpViewRenderer(
                viewPaths: $paths,
                extensions: self::splitList(env('VIEW_EXTENSIONS') ?: 'php') ?: ['php'],
                decorators: [],
                saveData: filter_var(env('VIEW_SAVE_DATA') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }

    /**
     * Split a colon/comma-separated config string into a trimmed list.
     *
     * @return list<string>
     */
    private static function splitList(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[:,]/', $value) ?: []),
            static fn(string $item): bool => $item !== '',
        ));
    }
}
