<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader};

/** Walks the requires[] dependency graph and reports any circular chain. */
final class DetectCyclesStage implements BootStageContract
{
    /** @var array<string, list<string>> domain => required domains */
    private array $domainMap = [];
    private array $resolved = [];
    private array $resolving = [];

    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        foreach ($this->moduleClasses as $class) {
            $m = $this->reader->read($class);
            $this->domainMap[$m['solves']] = $m['requires'] ?? [];
        }

        foreach (array_keys($this->domainMap) as $domain) {
            $this->visit($domain);
        }
    }

    private function visit(string $domain): void
    {
        if (isset($this->resolved[$domain])) {
            return;
        }
        if (isset($this->resolving[$domain])) {
            $cycle = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $domain;
            throw new BootException("Circular dependency detected: {$cycle}");
        }

        $this->resolving[$domain] = true;
        foreach ($this->domainMap[$domain] ?? [] as $dep) {
            if (isset($this->domainMap[$dep])) {
                $this->visit($dep);
            }
        }
        unset($this->resolving[$domain]);
        $this->resolved[$domain] = true;
    }
}
