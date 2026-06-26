<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;

/** In-memory CachePort for lockout tests. */
final class FakeCache implements CachePort
{
    /** @var array<string,mixed> */
    public array $store = [];

    public function get(string $key): mixed { return $this->store[$key] ?? null; }
    public function set(string $key, mixed $value, ?int $ttl = null): bool { $this->store[$key] = $value; return true; }
    public function delete(string $key): bool { unset($this->store[$key]); return true; }
    public function has(string $key): bool { return array_key_exists($key, $this->store); }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->store[$key] ??= $callback();
    }

    public function increment(string $key, int $by = 1): int
    {
        $this->store[$key] = (int) ($this->store[$key] ?? 0) + $by;
        return $this->store[$key];
    }

    public function deletePattern(string $pattern): int { return 0; }
    public function flush(): bool { $this->store = []; return true; }
}
