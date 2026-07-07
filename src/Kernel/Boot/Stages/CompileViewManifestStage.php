<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Compiles the view resolution map that the View plugin's renderer consumes.
 *
 * DETERMINISTIC PRIORITY MODEL
 * ----------------------------
 * Every view source declares a numeric `priority` — LOWER wins (searched first).
 * The cascade is fully ordered, never implicit or load-order dependent:
 *
 *   - PROJECT view paths default to priority 0  → always highest precedence.
 *   - PLUGIN view paths default to priority 100 → fallbacks below the project.
 *
 * So a project view ALWAYS overrides a plugin view of the same name by default.
 * A plugin may only preempt the project by EXPLICITLY declaring a lower (e.g.
 * negative) priority in its module.json — the single, opt-in escape hatch the
 * platform allows. Plain (unprefixed) names resolve down the global cascade;
 * `namespace::view` targets one source while still allowing a project override
 * (the renderer checks the project's `{namespace}/` folder first).
 *
 * Declaration shapes (module.json "views" / proj.json "views"):
 *   "views": "resources/views"                              // shorthand
 *   "views": { "path": "resources/views",
 *              "namespace": "task", "priority": 100, "global": true }
 *   "views": [ { ... }, { ... } ]                            // several sources
 *
 * Output (view-manifest.php):
 *   [ 'global'     => [ '/abs/project/views', '/abs/plugin/views', ... ],
 *     'namespaces' => [ 'task' => [ '/abs/plugin/task/views', ... ] ] ]
 */
final class CompileViewManifestStage implements BootStageContract
{
    /**
     * @param list<class-string> $moduleClasses
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        /** @var list<array{path:string,namespace:?string,priority:int,global:bool,order:int}> $entries */
        $entries = [];
        $order = 0;

        // ── PROJECT sources (priority 0 by default — highest precedence) ──────
        foreach ($this->projectSources() as $src) {
            $entries[] = $this->normalise($src, Paths::project(), defaultPriority: 0, order: $order++);
        }

        // ── PLUGIN sources (priority 100 by default — fallbacks) ──────────────
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            if (!isset($manifest['views'])) {
                continue;
            }
            $moduleDir = $this->moduleDir($moduleClass);
            $namespaceDefault = (string) ($manifest['name'] ?? '');
            foreach ($this->toList($manifest['views']) as $src) {
                $entries[] = $this->normalise(
                    $src,
                    $moduleDir,
                    defaultPriority: 100,
                    order: $order++,
                    defaultNamespace: $namespaceDefault,
                );
            }
        }

        // Stable sort by priority (LOWER first), ties broken by declaration order.
        usort($entries, static fn(array $a, array $b): int =>
            $a['priority'] <=> $b['priority'] ?: $a['order'] <=> $b['order']);

        $global = [];
        $namespaces = [];
        foreach ($entries as $entry) {
            $dir = $entry['path'];
            if ($dir === '' || !is_dir($dir)) {
                continue; // a declared-but-missing dir never breaks boot
            }
            if ($entry['global'] && !in_array($dir, $global, true)) {
                $global[] = $dir;
            }
            if ($entry['namespace'] !== null && $entry['namespace'] !== '') {
                $ns = $entry['namespace'];
                $namespaces[$ns] ??= [];
                if (!in_array($dir, $namespaces[$ns], true)) {
                    $namespaces[$ns][] = $dir;
                }
            }
        }

        ManifestWriter::write('view-manifest.php', [
            'global' => $global,
            'namespaces' => $namespaces,
        ]);
    }

    /**
     * Project-declared view sources: proj.json "views", then the conventional
     * default (resources/views) when nothing is declared.
     *
     * @return list<string|array<string,mixed>>
     */
    private function projectSources(): array
    {
        $sources = [];

        $projJson = Paths::project('proj.json');
        if (is_file($projJson)) {
            $decoded = json_decode((string) file_get_contents($projJson), true);
            if (is_array($decoded) && isset($decoded['views'])) {
                $sources = $this->toList($decoded['views']);
            }
        }

        if ($sources === []) {
            $sources[] = 'resources/views'; // platform default project view root
        }

        return $sources;
    }

    /**
     * @param string|array<string,mixed> $src
     * @return array{path:string,namespace:?string,priority:int,global:bool,order:int}
     */
    private function normalise(
        string|array $src,
        string $baseDir,
        int $defaultPriority,
        int $order,
        ?string $defaultNamespace = null,
    ): array {
        if (is_string($src)) {
            $src = ['path' => $src];
        }
        if (!isset($src['path']) || !is_string($src['path']) || trim($src['path']) === '') {
            throw new BootException('Invalid "views" declaration: each source needs a non-empty "path".');
        }

        $path = $src['path'];
        $abs = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? rtrim($path, '/\\')
            : rtrim($baseDir, '/') . '/' . ltrim($path, '/');

        $resolved = realpath($abs);

        return [
            'path' => $resolved !== false ? $resolved : $abs,
            'namespace' => isset($src['namespace']) ? (string) $src['namespace'] : $defaultNamespace,
            'priority' => isset($src['priority']) ? (int) $src['priority'] : $defaultPriority,
            'global' => isset($src['global']) ? (bool) $src['global'] : true,
            'order' => $order,
        ];
    }

    /**
     * @param mixed $views
     * @return list<string|array<string,mixed>>
     */
    private function toList(mixed $views): array
    {
        if (is_string($views)) {
            return [$views];
        }
        if (!is_array($views)) {
            return [];
        }
        // Associative single declaration vs. a list of declarations.
        if (isset($views['path']) || array_keys($views) !== range(0, count($views) - 1)) {
            return [$views];
        }
        return array_values($views);
    }

    /** @param class-string $moduleClass */
    private function moduleDir(string $moduleClass): string
    {
        $file = (new \ReflectionClass($moduleClass))->getFileName();
        if ($file === false) {
            throw new BootException("Cannot locate source file for [{$moduleClass}].");
        }
        return dirname($file);
    }
}
