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

    /** @throws CircularDependencyException|KernelException */
    public function resolve(string $service): DependencyGraph
    {
        $this->resolved = [];
        $this->resolving = [];
        $this->visit($service);

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
