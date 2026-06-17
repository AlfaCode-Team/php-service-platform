<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Loading;

final class DependencyGraph
{
    /** @param array<string, array<string, mixed>> $resolved */
    public function __construct(
        private readonly array $resolved
    ) {}

    /** @return list<string> */
    public function moduleNames(): array
    {
        return array_keys($this->resolved);
    }

    /** @return array<string, mixed> */
    public function entry(string $service): array
    {
        return $this->resolved[$service] ?? [];
    }
}
