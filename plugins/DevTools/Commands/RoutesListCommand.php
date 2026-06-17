<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * Aggregate and list every route declared across all module.json files — the
 * GDA single source of truth for routing. Optionally flags collisions where two
 * modules claim the same method+path.
 *
 * Usage: routes:list [--json] [--method=GET]
 */
final class RoutesListCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name = 'routes:list';
        $this->description = 'List all routes declared in module.json across plugins/ and modules/';

        $this->addOption('json', 'j', 'Output raw JSON');
        $this->addOption('method', 'm', 'Filter by HTTP method (GET, POST, ...)', acceptsValue: true);
    }

    protected function handle(): int
    {
        $filterMethod = strtoupper((string) ($this->option('method') ?? ''));
        $routes = $this->collect($filterMethod);

        if ($routes === []) {
            $this->warning('No routes declared in any module.json.');
            return self::SUCCESS;
        }

        if ($this->hasOption('json')) {
            $this->info(json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = array_map(
            static fn(array $r): array => [$r['method'], $r['path'], $r['handler'], $r['module']],
            $routes,
        );

        $this->table()
            ->headers(['Method', 'Path', 'Handler', 'Module'])
            ->rows($rows)
            ->render();

        $this->newLine();
        $this->reportCollisions($routes);
        $this->info(count($routes) . ' route(s) total.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{method:string,path:string,handler:string,module:string}>
     */
    private function collect(string $filterMethod): array
    {
        $routes = [];
        foreach (['plugins', 'modules'] as $base) {
            foreach (glob(getcwd() . "/{$base}/*/module.json") ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data)) {
                    continue;
                }
                $module = (string) ($data['name'] ?? basename(dirname($file)));
                foreach ($data['routes'] ?? [] as $route) {
                    $method = strtoupper((string) ($route['method'] ?? 'GET'));
                    if ($filterMethod !== '' && $method !== $filterMethod) {
                        continue;
                    }
                    $routes[] = [
                        'method'  => $method,
                        'path'    => (string) ($route['path'] ?? ''),
                        'handler' => (string) ($route['handler'] ?? ''),
                        'module'  => $module,
                    ];
                }
            }
        }

        usort($routes, static fn(array $a, array $b) => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);
        return $routes;
    }

    /** @param list<array{method:string,path:string,handler:string,module:string}> $routes */
    private function reportCollisions(array $routes): void
    {
        $seen = [];
        $collisions = [];
        foreach ($routes as $r) {
            $key = $r['method'] . ' ' . $r['path'];
            if (isset($seen[$key])) {
                $collisions[$key][] = $r['module'];
            } else {
                $seen[$key] = $r['module'];
            }
        }

        if ($collisions === []) {
            return;
        }

        $this->warning('Route collisions detected (same method+path in multiple modules):');
        foreach ($collisions as $key => $modules) {
            $this->warning("  {$key}  ->  " . $seen[$key] . ', ' . implode(', ', $modules));
        }
        $this->newLine();
    }
}
