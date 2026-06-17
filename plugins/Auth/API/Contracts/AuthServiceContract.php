<?php

declare(strict_types=1);

namespace Plugins\Auth\API\Contracts;

/**
 * Published authentication contract.
 *
 * Issues credentials; verification happens in the SecurityLayer classes that
 * run inside the SecurityGateway before any module loads.
 */
interface AuthServiceContract
{
    /**
     * Issue a signed JWT for a user.
     *
     * @param array{roles?:list<string>,permissions?:list<string>,tenant?:string} $claims
     */
    public function issueJwt(string $userId, array $claims = [], int $ttlSeconds = 3600): string;

    /**
     * Create a personal access token. Returns the plaintext token ONCE; only a
     * hash is persisted.
     *
     * @return array{id:string,token:string}
     */
    public function createPersonalAccessToken(string $userId, string $name = 'default'): array;

    /**
     * Revoke a personal access token by its id.
     */
    public function revokePersonalAccessToken(string $id): void;

    /**
     * Hash a plaintext password for storage (bcrypt/argon2 via HashingPort).
     */
    public function hashPassword(string $plain): string;

    /**
     * Verify a plaintext password against a stored hash (timing-safe).
     */
    public function verifyPassword(string $plain, string $hash): bool;
}
