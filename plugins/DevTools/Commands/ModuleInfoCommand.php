<?php

declare(strict_types=1);

namespace Plugins\DevTools\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * Show full details of a single module/plugin from its module.json.
 *
 * Usage: module:info <name>   (e.g. module:info auth)
 */
final class ModuleInfoCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name = 'module:info';
        $this->description = 'Show a module/plugin\'s manifest details';

        $this->addArgument('name', 'Module name as declared in module.json', required: true);
        $this->addOption('json', 'j', 'Output raw JSON');
    }

    protected function handle(): int
    {
        $name = (string) ($this->argument('name') ?? '');
        if ($name === '') {
            $this->error('A module name is required.');
            return self::FAILURE;
        }

        $manifest = $this->find($name);
        if ($manifest === null) {
            $this->error("No module named '{$name}' found under plugins/ or modules/.");
            return self::FAILURE;
        }

        [$data, $location] = $manifest;

        if ($this->hasOption('json')) {
            $this->info(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->section("Module: {$data['name']}");
        $this->info('Location : ' . $location);
        $this->info('Version  : ' . ($data['version'] ?? '—'));
        $this->info('Solves   : ' . ($data['solves'] ?? '—'));
        $this->info('Type     : ' . ($data['type'] ?? 'module'));
        $this->info('Requires : ' . $this->joinList($data['requires'] ?? []));
        $this->info('Exposes  : ' . $this->joinList($data['exposes'] ?? []));
        $this->info('Emits    : ' . $this->joinList($data['emits'] ?? []));
        $this->info('Config   : ' . $this->joinConfig($data['config'] ?? []));

        $routes = $data['routes'] ?? [];
        if ($routes !== []) {
            $this->newLine();
            $this->table()
                ->headers(['Method', 'Path', 'Handler'])
                ->rows(array_map(
                    static fn(array $r): array => [
                        strtoupper((string) ($r['method'] ?? 'GET')),
                        (string) ($r['path'] ?? ''),
                        (string) ($r['handler'] ?? ''),
                    ],
                    $routes,
                ))
                ->render();
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0:array<string,mixed>,1:string}|null
     */
    private function find(string $name): ?array
    {
        foreach (['plugins', 'modules'] as $base) {
            foreach (glob(getcwd() . "/{$base}/*/module.json") ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (is_array($data) && ($data['name'] ?? null) === $name) {
                    return [$data, $base . '/' . basename(dirname($file))];
                }
            }
        }
        return null;
    }

    /** @param list<mixed> $list */
    private function joinList(array $list): string
    {
        return $list === [] ? '—' : implode(', ', array_map('strval', $list));
    }

    /** @param list<mixed> $config */
    private function joinConfig(array $config): string
    {
        if ($config === []) {
            return '—';
        }
        $keys = array_map(
            static fn($c) => is_array($c) ? (string) ($c['key'] ?? '?') : (string) $c,
            $config,
        );
        return implode(', ', $keys);
    }
}
