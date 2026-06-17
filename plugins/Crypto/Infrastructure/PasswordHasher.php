<?php

declare(strict_types=1);

namespace Plugins\Crypto\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;

/**
 * Password hasher over PHP's native password_* functions.
 *
 * Defaults to bcrypt; pass algo 'argon2id' to use Argon2id where available.
 * check() is timing-safe via password_verify().
 */
final class PasswordHasher implements HashingPort
{
    public function __construct(
        private readonly string $algo = PASSWORD_BCRYPT,
        private readonly int $cost = 12,
    ) {
    }

    public function make(string $value, array $options = []): string
    {
        $hash = password_hash($value, $this->algo, $this->options($options));
        if (!is_string($hash)) {
            throw new KernelException('Password hashing failed.', layer: 'crypto.hasher');
        }
        return $hash;
    }

    public function check(string $value, string $hashedValue): bool
    {
        return $hashedValue !== '' && password_verify($value, $hashedValue);
    }

    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        return password_needs_rehash($hashedValue, $this->algo, $this->options($options));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    private function options(array $options): array
    {
        if ($this->algo === PASSWORD_BCRYPT) {
            return ['cost' => (int) ($options['cost'] ?? $this->cost)];
        }
        // Argon2 parameters fall back to PHP defaults unless overridden.
        return array_filter([
            'memory_cost' => isset($options['memory_cost']) ? (int) $options['memory_cost'] : null,
            'time_cost'   => isset($options['time_cost']) ? (int) $options['time_cost'] : null,
            'threads'     => isset($options['threads']) ? (int) $options['threads'] : null,
        ], static fn($v) => $v !== null);
    }
}
