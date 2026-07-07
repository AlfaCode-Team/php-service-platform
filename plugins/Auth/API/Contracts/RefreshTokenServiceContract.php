<?php

declare(strict_types=1);

namespace Plugins\Auth\API\Contracts;

use Plugins\Auth\API\DTOs\RefreshRotation;
use Plugins\Auth\API\DTOs\RefreshTokenIssued;

/**
 * RefreshTokenServiceContract — revocable long-lived first-party sessions paired
 * with the short-lived access JWT.
 *
 * Issue at login. Rotation is one-time-use: each rotate() revokes the presented
 * token and returns a new refresh token PLUS a fresh access token, with
 * rotation-family reuse detection. `tenantId` is a passthrough scope hint (baked
 * into the access token's `tnt` claim) — it is NOT re-verified here; tenant seat
 * checks live in the Tenancy selection flow.
 */
interface RefreshTokenServiceContract
{
    public function issue(
        string $userId,
        ?string $tenantId = null,
        ?string $device = null,
        ?string $ip = null,
    ): RefreshTokenIssued;

    /**
     * Rotate a refresh token: revoke it, mint a new refresh + access token.
     *
     * @throws \Plugins\Auth\Domain\Exceptions\InvalidRefreshTokenException (→ 401)
     */
    public function rotate(string $rawToken, ?string $ip = null): RefreshRotation;

    /** Revoke a single refresh token (logout this session). */
    public function revoke(string $rawToken): void;

    /** Revoke all of a user's refresh tokens (logout everywhere). */
    public function revokeAllForUser(string $userId): int;
}
