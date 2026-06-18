<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/** Reads routes[] from every module.json -> route-manifest.php (OPcache-cached). */
final class CompileRouteManifestStage implements BootStageContract
{
    /** Synthetic scope for project-layer routes (no owning module). */
    public const PROJECT_SCOPE = '__project__';

    /**
     * @param list<class-string> $moduleClasses
     * @param list<array{method: string, path: string, handler: string}> $projectRoutes
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly array $projectRoutes = [],
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $routes = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            foreach ($manifest['routes'] ?? [] as $route) {
                if (!isset($route['method'], $route['path'], $route['handler'])) {
                    throw new BootException(
                        "Invalid route in [{$moduleClass}] - each route needs method, path and handler."
                    );
                }
                if (!str_contains($route['handler'], '@')) {
                    throw new BootException(
                        "Route handler [{$route['handler']}] in [{$moduleClass}] must be in 'Controller@method' format."
                    );
                }
                $key = strtoupper($route['method']) . ' ' . $route['path'];
                if (isset($routes[$key])) {
                    throw new BootException(
                        "Duplicate route [{$key}] declared by [{$moduleClass}] and [{$routes[$key]['module']}]."
                    );
                }
                $routes[$key] = [
                    'handler' => $route['handler'],
                    'module' => $moduleClass,
                    'solves' => $manifest['solves'],
                ];
            }
        }

        // Project-layer routes — declared in project wiring (Kernel::withRoutes),
        // not in any module.json. They carry no module and resolve under the
        // synthetic PROJECT_SCOPE, which has an empty dependency graph.
        foreach ($this->projectRoutes as $route) {
            if (!isset($route['method'], $route['path'], $route['handler'])) {
                throw new BootException(
                    'Invalid project route - each route needs method, path and handler.'
                );
            }
            if (!str_contains($route['handler'], '@')) {
                throw new BootException(
                    "Project route handler [{$route['handler']}] must be in 'Controller@method' format."
                );
            }
            $key = strtoupper($route['method']) . ' ' . $route['path'];
            if (isset($routes[$key])) {
                throw new BootException(
                    "Duplicate route [{$key}]: project route conflicts with [{$routes[$key]['module']}]."
                );
            }
            $routes[$key] = [
                'handler' => $route['handler'],
                'module' => null,
                'solves' => self::PROJECT_SCOPE,
            ];
        }

        ManifestWriter::write('route-manifest.php', $routes);
    }
}
