<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Services;

use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\Contracts\RefreshTokenServiceContract;
use Plugins\Auth\API\DTOs\RefreshRotation;
use Plugins\Auth\API\DTOs\RefreshTokenIssued;
use Plugins\Auth\Application\Ports\RefreshTokenStore;
use Plugins\Auth\Domain\Exceptions\InvalidRefreshTokenException;
use Plugins\Auth\Support\Token;

/**
 * RefreshTokenService — revocable long-lived first-party sessions.
 *
 * Security posture:
 *   - Only the SHA-256 of the token is stored; the raw value is returned once.
 *   - Rotation is ONE-TIME-USE: rotate() revokes the presented token before
 *     issuing a new one, so a captured-then-reused token fails.
 *   - Rotation-family reuse detection: replaying a revoked token (or a rotation
 *     race) burns the whole family and forces re-authentication.
 *   - rotate() mints a fresh access JWT via AuthService in the same call.
 *
 * Tenant-agnostic: `tenantId` rides through as a scope hint for the access
 * token's `tnt` claim but is NEVER re-verified here — tenant seat membership is
 * re-checked in the Tenancy tenant-selection flow, not on every refresh.
 */
final class RefreshTokenService implements RefreshTokenServiceContract
{
    public function __construct(
        private readonly RefreshTokenStore $tokens,
        private readonly AuthServiceContract $auth,
        private readonly int $refreshTtl = 2592000, // 30 days
        private readonly int $accessTtl = 900,      // 15 minutes
    ) {}

    public function issue(
        string $userId,
        ?string $tenantId = null,
        ?string $device = null,
        ?string $ip = null,
    ): RefreshTokenIssued {
        $tokenId   = Token::ulid();
        $rawToken  = Token::random();
        $expiresAt = $this->expiry($this->refreshTtl);

        // A freshly-issued token is the ROOT of its own rotation family.
        $this->tokens->store($tokenId, $tokenId, $userId, Token::hash($rawToken), $tenantId, $device, $ip, $expiresAt);

        return new RefreshTokenIssued($tokenId, $rawToken, $expiresAt->format(\DateTimeInterface::RFC3339));
    }

    public function rotate(string $rawToken, ?string $ip = null): RefreshRotation
    {
        $record = $this->tokens->findByHash(Token::hash($rawToken));
        if ($record === null) {
            throw InvalidRefreshTokenException::invalid();
        }

        // Reuse detection: a known-but-already-revoked token is a replay of a
        // token that was rotated away (or stolen). Burn the whole family.
        if ($record->revoked) {
            $this->tokens->revokeFamily($record->familyId);
            throw InvalidRefreshTokenException::reuseDetected();
        }

        // One-time use, atomically: only the request that wins the conditional
        // revoke may proceed. A concurrent rotation loses the race (0 rows) and
        // is treated as reuse — burn the family.
        if (!$this->tokens->revokeIfActive($record->tokenId)) {
            $this->tokens->revokeFamily($record->familyId);
            throw InvalidRefreshTokenException::reuseDetected();
        }

        $tenantId = $record->tenantId;

        // Issue the replacement refresh token (same scope, same family).
        $newRawToken = Token::random();
        $newTokenId  = Token::ulid();
        $refreshExp  = $this->expiry($this->refreshTtl);
        $this->tokens->store($newTokenId, $record->familyId, $record->userId, Token::hash($newRawToken), $tenantId, null, $ip, $refreshExp);

        // Mint the paired access token (tnt is a passthrough hint, not re-verified).
        $accessToken = $this->auth->issueJwt(
            $record->userId,
            ['tnt' => $tenantId ?? '', 'roles' => []],
            $this->accessTtl,
        );

        return new RefreshRotation(
            accessToken:      $accessToken,
            expiresIn:        $this->accessTtl,
            refreshToken:     $newRawToken,
            refreshExpiresAt: $refreshExp->format(\DateTimeInterface::RFC3339),
            tenantId:         $tenantId ?? '',
        );
    }

    public function revoke(string $rawToken): void
    {
        $record = $this->tokens->findActiveByHash(Token::hash($rawToken));
        if ($record === null) {
            return;
        }
        $this->tokens->revoke($record->tokenId);
    }

    public function revokeAllForUser(string $userId): int
    {
        return $this->tokens->revokeAllForUser($userId);
    }

    private function expiry(int $ttlSeconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(60, $ttlSeconds) . 'S'));
    }
}
