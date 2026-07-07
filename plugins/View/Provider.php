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
            // The compiled view manifest is the single source of truth for the
            // deterministic priority cascade: project view paths first, plugin
            // paths after, plus the namespace map for `plugin::view` lookups.
            // (Built by CompileViewManifestStage at boot — see that stage.)
            $manifest = self::loadViewManifest();

            $paths = $manifest['global'];

            // Optional env override/augmentation. VIEW_PATHS is PREPENDED so an
            // operator can inject an even-higher-priority project path at runtime
            // without ever letting a plugin outrank the project implicitly.
            $envPaths = array_values(array_filter(array_map(static function (string $path): ?string {
                if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
                    return is_dir($path) ? rtrim($path, '/') : null;
                }
                $projectPath = Paths::project($path);
                return is_dir($projectPath) ? $projectPath : null;
            }, self::splitList(env('VIEW_PATHS') ?: ''))));

            $paths = array_values(array_unique(array_merge($envPaths, $paths)));

            if ($paths === [] && $manifest['namespaces'] === []) {
                // Last-resort default so a project with no declared views still works.
                $default = Paths::project('resources/views');
                if (is_dir($default)) {
                    $paths[] = $default;
                }
            }

            return new PhpViewRenderer(
                viewPaths: $paths,
                extensions: self::splitList(env('VIEW_EXTENSIONS') ?: 'php') ?: ['php'],
                decorators: [],
                saveData: filter_var(env('VIEW_SAVE_DATA') ?: 'false', FILTER_VALIDATE_BOOLEAN),
                namespaces: $manifest['namespaces'],
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }

    /**
     * Load the compiled view manifest (project-first global cascade + namespace
     * map). Returns an empty, well-shaped structure when the manifest has not
     * been compiled yet, so the renderer degrades gracefully.
     *
     * @return array{global: list<string>, namespaces: array<string,list<string>>}
     */
    private static function loadViewManifest(): array
    {
        $path = Paths::cache('manifests/view-manifest.php');
        $data = is_file($path) ? require $path : null;

        return [
            'global' => is_array($data['global'] ?? null) ? array_values($data['global']) : [],
            'namespaces' => is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [],
        ];
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
