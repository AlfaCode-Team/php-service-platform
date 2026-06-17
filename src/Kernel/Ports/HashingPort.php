<?php
declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * One-way password hashing port (bcrypt / argon2).
 *
 * Use for credentials. NEVER use a plain hash (sha256/md5) for passwords — this
 * port produces salted, work-factored hashes and supports transparent rehashing
 * when the cost parameters change.
 */
interface HashingPort
{
    /**
     * Hash a value. $options may carry algorithm parameters (e.g. cost).
     *
     * @param array<string,mixed> $options
     */
    public function make(string $value, array $options = []): string;

    /**
     * Verify a plaintext value against a stored hash (timing-safe).
     */
    public function check(string $value, string $hashedValue): bool;

    /**
     * Whether the stored hash should be re-made with current options.
     *
     * @param array<string,mixed> $options
     */
    public function needsRehash(string $hashedValue, array $options = []): bool;
}
