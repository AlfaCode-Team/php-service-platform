<?php
declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;

/**
 * InMemoryCache — project-supplied CachePort adapter (PROJECT LAYER).
 *
 * A dependency-free CachePort implementation so the framework boots without
 * Redis/Memcached. Swap for a RedisAdapter in bootstrap/app.php for production.
 *
 * Swoole note: state is per-worker (not shared across workers). For a shared
 * cache use a real backend. This instance is created once per worker in
 * bootstrap/app.php and is intentionally NOT a static/global singleton.
 *
 * @phpstan-type Entry array{value: mixed, expires: int|null}
 */
final class InMemoryCache implements CachePort
{
    /** @var array<string, array{value: mixed, expires: int|null}> */
    private array $store = [];

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }
        return $this->store[$key]['value'];
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store[$key] = [
            'value'   => $value,
            'expires' => $ttl !== null ? time() + $ttl : null,
        ];
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->store[$key])) {
            return false;
        }
        $expires = $this->store[$key]['expires'];
        if ($expires !== null && $expires < time()) {
            unset($this->store[$key]);
            return false;
        }
        return true;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = $this->has($key) ? (int) $this->get($key) : 0;
        $next = $current + $by;
        $expires = $this->store[$key]['expires'] ?? null;
        $this->store[$key] = ['value' => $next, 'expires' => $expires];
        return $next;
    }

    public function deletePattern(string $pattern): int
    {
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
        $count = 0;
        foreach (array_keys($this->store) as $key) {
            if (preg_match($regex, $key) === 1) {
                unset($this->store[$key]);
                $count++;
            }
        }
        return $count;
    }

    public function flush(): bool
    {
        $this->store = [];
        return true;
    }
}
