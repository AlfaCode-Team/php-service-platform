<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{ManifestReader, ManifestWriter};

/** Reads commands[] from every module.json -> command-manifest.php. */
final class CompileCommandManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $commands = [];
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            foreach ($manifest['commands'] ?? [] as $command) {
                $name = is_array($command) ? ($command['name'] ?? null) : $command;
                if ($name === null) {
                    continue;
                }
                $commands[$name] = [
                    'handler' => is_array($command) ? ($command['handler'] ?? $name) : $name,
                    'module' => $moduleClass,
                    'solves' => $manifest['solves'],
                ];
            }
        }

        ManifestWriter::write('command-manifest.php', $commands);
    }
}
