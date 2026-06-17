<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * List every discovered GDA module/plugin by reading its module.json — the
 * single source of truth. Scans plugins/ and modules/ under the project root.
 *
 * Usage: module:list [--json]
 */
final class ModuleListCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name = 'module:list';
        $this->description = 'List all discovered modules/plugins from their module.json';

        $this->addOption('json', 'j', 'Output raw JSON instead of a table');
    }

    protected function handle(): int
    {
        $modules = $this->discover();

        if ($modules === []) {
            $this->warning('No module.json files found under plugins/ or modules/.');
            return self::SUCCESS;
        }

        if ($this->hasOption('json')) {
            $this->info(json_encode(array_values($modules), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($modules as $m) {
            $rows[] = [
                $m['name'],
                $m['solves'],
                $m['type'],
                (string) $m['routes'],
                (string) $m['exposes'],
                $m['location'],
            ];
        }

        $this->table()
            ->headers(['Name', 'Solves', 'Type', 'Routes', 'Exposes', 'Location'])
            ->rows($rows)
            ->render();

        $this->newLine();
        $this->info(count($modules) . ' module(s) discovered.');

        return self::SUCCESS;
    }

    /**
     * @return array<string,array{name:string,solves:string,type:string,routes:int,exposes:int,location:string}>
     */
    private function discover(): array
    {
        $found = [];
        foreach (['plugins', 'modules'] as $base) {
            foreach (glob(getcwd() . "/{$base}/*/module.json") ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data) || !isset($data['name'])) {
                    continue;
                }
                $found[(string) $data['name']] = [
                    'name'     => (string) $data['name'],
                    'solves'   => (string) ($data['solves'] ?? '—'),
                    'type'     => (string) ($data['type'] ?? 'module'),
                    'routes'   => count($data['routes'] ?? []),
                    'exposes'  => count($data['exposes'] ?? []),
                    'location' => $base . '/' . basename(dirname($file)),
                ];
            }
        }
        ksort($found);
        return $found;
    }
}
