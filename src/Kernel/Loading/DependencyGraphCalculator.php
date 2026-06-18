<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Loading;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{CircularDependencyException, KernelException};

final class DependencyGraphCalculator
{
    /** @var array<string, array<string, mixed>> */
    private array $resolved = [];
    /** @var array<string, true> */
    private array $resolving = [];

    /** @param array{services?: array<string, array<string, mixed>>} $manifest */
    public function __construct(
        private readonly array $manifest
    ) {}

    /**
     * Resolve the dependency graph for a service, optionally seeding extra
     * module domains the matched route declared in its own requires[].
     *
     * The synthetic '__project__' scope carries no requires of its own, so a
     * project route opts into specific plugins per-route via $additional rather
     * than loading them for every project page. Each extra domain is resolved
     * (with its transitive requires) into the same graph.
     *
     * @param list<string> $additional extra module domains to pull into the graph
     * @throws CircularDependencyException|KernelException
     */
    public function resolve(string $service, array $additional = []): DependencyGraph
    {
        $this->resolved = [];
        $this->resolving = [];
        $this->visit($service);
        foreach ($additional as $dep) {
            $this->visit($dep);
        }

        return new DependencyGraph($this->resolved);
    }

    private function visit(string $service): void
    {
        if (isset($this->resolved[$service])) {
            return;
        }
        if (isset($this->resolving[$service])) {
            $path = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $service;
            throw new CircularDependencyException("Circular dependency detected: {$path}");
        }

        $this->resolving[$service] = true;

        $entry = $this->manifest['services'][$service]
            ?? throw new KernelException(
                "Service [{$service}] not found in service-manifest.php",
                layer: 'kernel.loading',
                context: ['service' => $service],
            );

        foreach ($entry['requires'] ?? [] as $dep) {
            $this->visit($dep);
        }

        unset($this->resolving[$service]);
        $this->resolved[$service] = $entry;
    }
}
