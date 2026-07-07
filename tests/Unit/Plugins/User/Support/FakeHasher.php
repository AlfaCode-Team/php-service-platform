<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;

/** Deterministic, fast HashingPort (no real bcrypt cost). */
final class FakeHasher implements HashingPort
{
    public bool $needsRehash = false;

    public function make(string $value, array $options = []): string
    {
        // 60 chars, bcrypt-shaped so the entity's format guard passes.
        return '$2y$12$' . substr(hash('sha256', $value), 0, 53);
    }

    public function check(string $value, string $hashedValue): bool
    {
        return hash_equals($this->make($value), $hashedValue);
    }

    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        return $this->needsRehash;
    }
}
