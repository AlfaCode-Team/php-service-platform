<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\RefreshRotation;
use Plugins\Tenancy\API\DTOs\RefreshTokenIssued;

/**
 * RefreshTokenServiceContract — revocable long-lived sessions paired with the
 * short-lived access JWT.
 *
 * Issue at login (unscoped) or after tenant selection (scoped). Rotation is
 * one-time-use: each rotate() revokes the presented token and returns a new
 * refresh token PLUS a fresh access token, re-checking tenant membership so a
 * revoked seat cannot refresh its way back in.
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
     * @throws \Plugins\Tenancy\Domain\Exceptions\InvalidRefreshTokenException (→ 401)
     */
    public function rotate(string $rawToken, ?string $ip = null): RefreshRotation;

    /** Revoke a single refresh token (logout this session). */
    public function revoke(string $rawToken): void;

    /** Revoke all of a user's refresh tokens (logout everywhere). */
    public function revokeAllForUser(string $userId): int;
}
