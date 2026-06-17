<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * List registered projects from projects/<name>/proj.json, enriched with the
 * domains declared in projects/projects.json.
 *
 * Usage: project:list [--json]
 */
final class ProjectListCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name = 'project:list';
        $this->description = 'List registered projects and their domains';

        $this->addOption('json', 'j', 'Output raw JSON');
    }

    protected function handle(): int
    {
        $projects = $this->discover();

        if ($projects === []) {
            $this->warning('No projects found under projects/.');
            return self::SUCCESS;
        }

        if ($this->hasOption('json')) {
            $this->info(json_encode(array_values($projects), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = array_map(
            static fn(array $p): array => [
                $p['name'],
                $p['version'],
                $p['domains'] === '' ? '—' : $p['domains'],
                $p['features'] === '' ? '—' : $p['features'],
            ],
            array_values($projects),
        );

        $this->table()
            ->headers(['Name', 'Version', 'Domains', 'Features'])
            ->rows($rows)
            ->render();

        $this->newLine();
        $this->info(count($projects) . ' project(s).');

        return self::SUCCESS;
    }

    /**
     * @return array<string,array{name:string,version:string,domains:string,features:string}>
     */
    private function discover(): array
    {
        $root = getcwd() . '/projects';
        $domainMap = $this->loadDomainMap($root . '/projects.json');

        $projects = [];
        foreach (glob($root . '/*/proj.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data) || !isset($data['name'])) {
                continue;
            }
            $name = (string) $data['name'];
            $projects[$name] = [
                'name'     => $name,
                'version'  => (string) ($data['version'] ?? '—'),
                'domains'  => implode(', ', $domainMap[$name] ?? []),
                'features' => implode(', ', array_map('strval', $data['features'] ?? [])),
            ];
        }

        ksort($projects);
        return $projects;
    }

    /**
     * @return array<string,list<string>>
     */
    private function loadDomainMap(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return [];
        }

        $map = [];
        foreach ($data as $name => $entry) {
            if (is_array($entry) && isset($entry['domains']) && is_array($entry['domains'])) {
                $map[(string) $name] = array_map('strval', $entry['domains']);
            }
        }
        return $map;
    }
}
