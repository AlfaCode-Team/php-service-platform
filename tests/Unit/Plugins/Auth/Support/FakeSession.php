<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/** Array-backed SessionPort double; tracks regenerate()/invalidate() calls. */
final class FakeSession implements SessionPort
{
    /** @var array<string,mixed> */
    private array $data = [];
    private string $id = 'sess-initial';
    public int $regenerations = 0;
    public int $invalidations = 0;

    public function start(?string $id = null): void {}
    public function id(): string { return $this->id; }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function has(string $key): bool { return array_key_exists($key, $this->data); }

    public function pull(string $key, mixed $default = null): mixed
    {
        $v = $this->data[$key] ?? $default;
        unset($this->data[$key]);
        return $v;
    }

    public function push(string $key, mixed $value): void
    {
        $arr = $this->data[$key] ?? [];
        $arr[] = $value;
        $this->data[$key] = $arr;
    }

    public function increment(string $key, int $by = 1): int
    {
        $this->data[$key] = (int) ($this->data[$key] ?? 0) + $by;
        return $this->data[$key];
    }

    public function forget(string $key): void { unset($this->data[$key]); }
    public function flush(): void { $this->data = []; }

    /** @return array<string,mixed> */
    public function all(): array { return $this->data; }

    public function flash(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function reflash(): void {}
    public function token(): string { return 'csrf-token'; }
    public function regenerateToken(): void {}

    public function regenerate(): void
    {
        $this->regenerations++;
        $this->id = 'sess-' . $this->regenerations;
    }

    public function invalidate(): void
    {
        $this->invalidations++;
        $this->data = [];
        $this->id = 'sess-invalidated-' . $this->invalidations;
    }

    public function shouldPersist(): bool { return true; }
    public function save(): void {}
}
