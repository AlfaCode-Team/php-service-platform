<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{ManifestReader, ManifestWriter};

/**
 * Compiles the service graph the OnDemandLoader uses at runtime.
 * service-manifest.php: ['services' => [domain => {module, requires, exposes, name}]].
 * Only module-to-module requires are recorded (ports resolve via CoreContainer).
 */
final class CompileServiceManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $manifests = [];
        $domains = [];
        foreach ($this->moduleClasses as $class) {
            $m = $this->reader->read($class);
            $manifests[$class] = $m;
            $domains[$m['solves']] = true;
        }

        $services = [];
        foreach ($this->moduleClasses as $class) {
            $m = $manifests[$class];
            $domain = $m['solves'];

            $moduleRequires = array_values(array_filter(
                $m['requires'] ?? [],
                static fn($dep) => isset($domains[$dep]),
            ));

            $services[$domain] = [
                'name' => $m['name'] ?? $domain,
                'module' => $class,
                'requires' => $moduleRequires,
                'exposes' => $m['exposes'] ?? [],
            ];
        }

        ManifestWriter::write('service-manifest.php', ['services' => $services]);
    }
}
