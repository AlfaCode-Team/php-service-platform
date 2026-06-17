<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * QueuePort — the ONLY way modules enqueue background work.
 * The kernel defines this interface; the project provides the adapter.
 */
interface QueuePort
{
    /** @param array<string, mixed> $payload @return string job id */
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string;

    /** @param array<string, mixed> $payload @return string job id */
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string;

    public function size(string $queue = 'default'): int;
}
