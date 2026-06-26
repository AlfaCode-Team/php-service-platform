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
     * Tenant context is carried by the `tnt` claim (multi-tenant control plane).
     * Omit it (or pass '') for a login/unscoped token that routes to the central
     * connection; set it ONLY after verifying membership in the central
     * `user_tenants` table at tenant-selection time. `tenant` is accepted as a
     * legacy alias.
     *
     * @param array{roles?:list<string>,permissions?:list<string>,tnt?:string,tenant?:string} $claims
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
