<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/** Reads routes[] from every module.json -> route-manifest.php (OPcache-cached). */
final class CompileRouteManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
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

        ManifestWriter::write('route-manifest.php', $routes);
    }
}
