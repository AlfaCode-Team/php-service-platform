<?php

declare(strict_types=1);

namespace Plugins\Auth\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

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
     * @param list<string> $abilities  Scopes granted to the token (become Identity.permissions).
     * @param int|null     $ttlSeconds Absolute lifetime; null = non-expiring token.
     *
     * @return array{id:string,token:string}
     */
    public function createPersonalAccessToken(
        string $userId,
        string $name = 'default',
        array $abilities = [],
        ?int $ttlSeconds = null,
    ): array;

    /**
     * Revoke a personal access token by its id.
     */
    public function revokePersonalAccessToken(string $id): void;

    /**
     * Establish a stateful web/AJAX login on the given session.
     *
     * Rotates the session id (fixation defence) and stores the identity so
     * SessionAuthStage can rebuild an Identity on subsequent requests. Call AFTER
     * verifying credentials (e.g. UserServiceContract::verifyCredentials).
     *
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function startSession(
        SessionPort $session,
        string $userId,
        array $roles = [],
        array $permissions = [],
        string $tenantId = '',
    ): void;

    /** Tear down a web/AJAX login: clear attributes and rotate the session id. */
    public function endSession(SessionPort $session): void;

    /**
     * Revoke a JWT before its natural expiry by deny-listing its `jti` claim.
     * No-op when no cache/revocation backend is configured.
     *
     * @param string $jti        The token's unique id (the `jti` claim).
     * @param int    $ttlSeconds Keep the deny-list entry at least this long
     *                           (set it to the token's remaining lifetime).
     */
    public function revokeJwt(string $jti, int $ttlSeconds = 3600): void;

    /**
     * Hash a plaintext password for storage (bcrypt/argon2 via HashingPort).
     */
    public function hashPassword(string $plain): string;

    /**
     * Verify a plaintext password against a stored hash (timing-safe).
     */
    public function verifyPassword(string $plain, string $hash): bool;
}
