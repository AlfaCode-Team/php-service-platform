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

        // PASS 1 — read every module manifest once and collect the full set of
        // domains some module solves(). Building this BEFORE compiling any route
        // means a route's requires[] can name a domain declared by a module that
        // appears later in the list (order-independent validation).
        $manifests    = [];
        $knownDomains = [];
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest               = $this->reader->read($moduleClass);
            $manifests[$moduleClass] = $manifest;
            $knownDomains[$manifest['solves']] = true;
        }

        // PASS 2a — plugin routes (from module.json). A plugin route normally
        // gets its deps via its module's solves graph, but it MAY also declare
        // route-level requires[] (validated + honoured by LoadStage), kept
        // consistent with project routes.
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $manifests[$moduleClass];
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
                    'requires' => $this->validateRequires(
                        $this->normalizeRequires($route['requires'] ?? []),
                        $knownDomains,
                        "Route [{$key}] in [{$moduleClass}]",
                    ),
                ];
            }
        }

        // PASS 2b — project-layer routes (Kernel::withRoutes / proj.json), not in
        // any module.json. They carry no module and resolve under the synthetic
        // PROJECT_SCOPE, whose dependency graph is empty — so route-level
        // requires[] is the ONLY way a project page pulls in a plugin.
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

            $routes[$key] = [
                'handler' => $route['handler'],
                'module' => null,
                'solves' => self::PROJECT_SCOPE,
                'overrides' => $routes[$key]['module'] ?? null,
                'filters' => $this->normalizeFilters($route['filters'] ?? []),
                // Per-route module dependencies seeded into this request's graph
                // by LoadStage. Each must name a real module domain — fail at boot.
                'requires' => $this->validateRequires(
                    $this->normalizeRequires($route['requires'] ?? []),
                    $knownDomains,
                    "Project route [{$key}]",
                ),
            ];
        }

        ManifestWriter::write('route-manifest.php', $routes);
    }

    /**
     * Ensure every declared dependency names a domain some module solves(),
     * failing fast at boot with a descriptive message instead of a request-time
     * 500. Returns the list unchanged on success.
     *
     * @param list<string>         $requires
     * @param array<string, true>  $knownDomains
     * @return list<string>
     */
    private function validateRequires(array $requires, array $knownDomains, string $context): array
    {
        foreach ($requires as $dep) {
            if (!isset($knownDomains[$dep])) {
                throw new BootException(
                    "{$context} requires unknown module domain [{$dep}]. "
                    . 'No registered module solves it — check the spelling and that the '
                    . 'plugin is listed in withModules()/withEssentialModules().'
                );
            }
        }

        return $requires;
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
