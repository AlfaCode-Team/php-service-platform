<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader};

/** Verifies no two modules declare the same solves() domain string. */
final class DetectConflictsStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $seen = $conflicts = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $domain = $this->reader->read($moduleClass)['solves'] ?? null;
            if ($domain === null) {
                throw new BootException("Module [{$moduleClass}] is missing a 'solves' domain in module.json");
            }

            if (isset($seen[$domain])) {
                $conflicts[] = "Both [{$seen[$domain]}] and [{$moduleClass}] declare solves: '{$domain}'";
            } else {
                $seen[$domain] = $moduleClass;
            }
        }

        if ($conflicts !== []) {
            throw new BootException("Module conflict detected:\n  " . implode("\n  ", $conflicts));
        }
    }
}
