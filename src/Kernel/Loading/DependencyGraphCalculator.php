<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Loading;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\{CircularDependencyException, KernelException};

final class DependencyGraphCalculator
{
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
     * STATELESS: all traversal state lives in locals passed by reference, never
     * on $this. One calculator instance is shared across every request (and,
     * under OpenSwoole, across concurrent coroutines) — keeping zero mutable
     * instance state makes concurrent resolve() calls provably non-interfering,
     * independent of whether any future edit introduces an I/O yield point.
     *
     * @param list<string> $additional extra module domains to pull into the graph
     * @throws CircularDependencyException|KernelException
     */
    public function resolve(string $service, array $additional = []): DependencyGraph
    {
        /** @var array<string, array<string, mixed>> $resolved */
        $resolved = [];
        /** @var array<string, true> $resolving */
        $resolving = [];

        $this->visit($service, $resolved, $resolving);
        foreach ($additional as $dep) {
            $this->visit($dep, $resolved, $resolving);
        }

        return new DependencyGraph($resolved);
    }

    /**
     * @param array<string, array<string, mixed>> $resolved
     * @param array<string, true>                 $resolving
     */
    private function visit(string $service, array &$resolved, array &$resolving): void
    {
        if (isset($resolved[$service])) {
            return;
        }
        if (isset($resolving[$service])) {
            $path = implode(' -> ', array_keys($resolving)) . ' -> ' . $service;
            throw new CircularDependencyException("Circular dependency detected: {$path}");
        }

        $resolving[$service] = true;

        $entry = $this->manifest['services'][$service]
            ?? throw new KernelException(
                "Service [{$service}] not found in service-manifest.php",
                layer: 'kernel.loading',
                context: ['service' => $service],
            );

        foreach ($entry['requires'] ?? [] as $dep) {
            $this->visit($dep, $resolved, $resolving);
        }

        unset($resolving[$service]);
        $resolved[$service] = $entry;
    }
}
