<?php
declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;
interface CachePort
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function increment(string $key, int $by = 1): int;
    public function deletePattern(string $pattern): int;
    public function flush(): bool;
}
