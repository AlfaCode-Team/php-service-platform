<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{ManifestReader, ManifestWriter};

/** Reads jobs[] from every module.json -> job-manifest.php. */
final class CompileJobManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $jobs = [];
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            foreach ($manifest['jobs'] ?? [] as $job) {
                $name = is_array($job) ? ($job['name'] ?? null) : $job;
                if ($name === null) {
                    continue;
                }
                $jobs[$name] = [
                    'handler' => is_array($job) ? ($job['handler'] ?? $name) : $name,
                    'queue' => is_array($job) ? ($job['queue'] ?? 'default') : 'default',
                    'module' => $moduleClass,
                    'solves' => $manifest['solves'],
                ];
            }
        }

        ManifestWriter::write('job-manifest.php', $jobs);
    }
}
