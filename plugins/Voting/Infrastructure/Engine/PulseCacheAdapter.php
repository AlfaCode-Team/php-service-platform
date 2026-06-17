<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Engine;

use AlfaCode\PulseEngine\Contract\CacheInterface;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;

/**
 * PulseCacheAdapter — bridges the kernel CachePort to pulse-engine's
 * CacheInterface so the Voting plugin can drive pulse-engine services
 * (RateLimiter, …) using the project's configured cache backend.
 *
 * This is the dependency seam that lets the Voting plugin "depend on"
 * pulse-engine without pulse-engine knowing anything about the kernel.
 */
final class PulseCacheAdapter implements CacheInterface
{
    public function __construct(
        private readonly CachePort $cache,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->cache->get($key);
        return $value ?? $default;
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        return $this->cache->set($key, $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->cache->delete($key);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($key);
    }
}
