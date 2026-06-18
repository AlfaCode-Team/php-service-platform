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

        // Set of every module domain that some module solves(). A route's
        // requires[] may only name a domain in this set — validated at boot so a
        // typo fails here with a clear message instead of a request-time 500.
        $knownDomains = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $knownDomains[$manifest['solves']] = true;
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
                    'filters' => $this->normalizeFilters($route['filters'] ?? []),
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
            // DETERMINISTIC PRIORITY: project routes are compiled AFTER every
            // plugin route and OVERRIDE a plugin route declaring the same
            // "METHOD path". This is the default project-over-plugin precedence —
            // never the reverse. Plugins cannot reclaim a route the project owns.
            $key = strtoupper($route['method']) . ' ' . $route['path'];

            // Per-route module dependencies. The '__project__' scope has no
            // requires of its own, so a project route names the plugin domains
            // it needs HERE; LoadStage seeds them into this request's graph only.
            // Lets one page use view.rendering without loading it for every
            // project route. Each must name a real module domain — fail at boot.
            $requires = $this->normalizeRequires($route['requires'] ?? []);
            foreach ($requires as $dep) {
                if (!isset($knownDomains[$dep])) {
                    throw new BootException(
                        "Project route [{$key}] requires unknown module domain [{$dep}]. "
                        . 'No registered module solves it — check the spelling and that the '
                        . 'plugin is listed in withModules()/withEssentialModules().'
                    );
                }
            }

            $routes[$key] = [
                'handler' => $route['handler'],
                'module' => null,
                'solves' => self::PROJECT_SCOPE,
                'overrides' => $routes[$key]['module'] ?? null,
                'filters' => $this->normalizeFilters($route['filters'] ?? []),
                'requires' => $requires,
            ];
        }

        ManifestWriter::write('route-manifest.php', $routes);
    }

    /**
     * Normalize a route's declared filters to a clean list of string specs.
     * Accepts a single string ("auth") or a list (["auth", "throttle:60"]).
     *
     * @param mixed $filters
     * @return list<string>
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (is_string($filters)) {
            $filters = [$filters];
        }
        if (!is_array($filters)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($f): string => trim((string) $f), $filters),
            static fn(string $f): bool => $f !== '',
        ));
    }

    /**
     * Normalize a route's declared module requires to a clean list of domain
     * strings. Accepts a single string ("view.rendering") or a list.
     *
     * @param mixed $requires
     * @return list<string>
     */
    private function normalizeRequires(mixed $requires): array
    {
        if (is_string($requires)) {
            $requires = [$requires];
        }
        if (!is_array($requires)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($r): string => trim((string) $r), $requires),
            static fn(string $r): bool => $r !== '',
        ));
    }
}
