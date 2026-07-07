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

        // Synthetic project scope — present only when the project declares its own
        // routes (Kernel::withRoutes). It has no module and no requires, so its
        // dependency graph is empty: the controller autowires from the request
        // container without running any module register().
        if ($this->projectRoutes !== []) {
            $services[CompileRouteManifestStage::PROJECT_SCOPE] = [
                'name' => CompileRouteManifestStage::PROJECT_SCOPE,
                'module' => null,
                'requires' => [],
                'exposes' => [],
            ];
        }

        ManifestWriter::write('service-manifest.php', ['services' => $services]);
    }
}
