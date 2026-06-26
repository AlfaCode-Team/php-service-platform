<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Tenancy\API\Contracts\RefreshTokenServiceContract;
use Plugins\Tenancy\API\DTOs\RefreshRotation;
use Plugins\Tenancy\API\DTOs\RefreshTokenIssued;
use Plugins\Tenancy\Application\Ports\AuditSink;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Application\Ports\RefreshTokenStore;
use Plugins\Tenancy\Domain\Exceptions\InvalidRefreshTokenException;
use Plugins\Tenancy\Support\Token;

/**
 * RefreshTokenService — revocable long-lived sessions.
 *
 * Security posture:
 *   - Only the SHA-256 of the token is stored; the raw value is returned once.
 *   - Rotation is ONE-TIME-USE: rotate() revokes the presented token before
 *     issuing a new one, so a captured-then-reused token fails (it is already
 *     revoked → treated as invalid).
 *   - On rotation of a tenant-scoped token, membership is RE-CHECKED against
 *     central `user_tenants`; a revoked/suspended seat cannot refresh back in.
 *   - rotate() also mints a fresh access JWT (the `tnt` claim) via the Auth
 *     module, so the client gets a ready-to-use credential in one call.
 */
final class RefreshTokenService implements RefreshTokenServiceContract
{
    public function __construct(
        private readonly RefreshTokenStore $tokens,
        private readonly AuthServiceContract $auth,
        private readonly MembershipReader $memberships,
        private readonly AuditSink $audit,
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

        // Reuse detection: the presented token is known but ALREADY revoked — a
        // replay of a token that was rotated away (or stolen). Burn the whole
        // family so the legitimate descendant chain is invalidated too.
        if ($record->revoked) {
            $this->tokens->revokeFamily($record->familyId);
            $this->audit->record('auth.refresh_reuse_detected', $record->userId, $record->tenantId, ['family' => $record->familyId], $ip);
            throw InvalidRefreshTokenException::reuseDetected();
        }

        // One-time use, atomically: only the request that wins the conditional
        // revoke may proceed. A concurrent rotation of the same token loses the
        // race (0 rows) and is treated as reuse — burn the family.
        if (!$this->tokens->revokeIfActive($record->tokenId)) {
            $this->tokens->revokeFamily($record->familyId);
            $this->audit->record('auth.refresh_reuse_detected', $record->userId, $record->tenantId, ['family' => $record->familyId, 'race' => true], $ip);
            throw InvalidRefreshTokenException::reuseDetected();
        }

        $tenantId = $record->tenantId;
        $roles    = [];
        $role     = null;

        if ($tenantId !== null) {
            $membership = $this->memberships->find($record->userId, $tenantId);
            if ($membership === null || !$membership->isRoutable()) {
                $this->audit->record('auth.refresh_denied', $record->userId, $tenantId, ['reason' => 'membership'], $ip);
                throw InvalidRefreshTokenException::membershipRevoked();
            }
            $role  = $membership->role;
            $roles = [$role];
        }

        // Issue the replacement refresh token (same scope).
        $newRawToken = Token::random();
        $newTokenId  = Token::ulid();
        $refreshExp  = $this->expiry($this->refreshTtl);
        // Replacement stays in the SAME family so reuse detection spans the chain.
        $this->tokens->store($newTokenId, $record->familyId, $record->userId, Token::hash($newRawToken), $tenantId, null, $ip, $refreshExp);

        // Mint the paired access token.
        $accessToken = $this->auth->issueJwt(
            $record->userId,
            ['tnt' => $tenantId ?? '', 'roles' => $roles],
            $this->accessTtl,
        );

        $this->audit->record('auth.refresh', $record->userId, $tenantId, [], $ip);

        return new RefreshRotation(
            accessToken:      $accessToken,
            expiresIn:        $this->accessTtl,
            refreshToken:     $newRawToken,
            refreshExpiresAt: $refreshExp->format(\DateTimeInterface::RFC3339),
            tenantId:         $tenantId ?? '',
            role:             $role,
        );
    }

    public function revoke(string $rawToken): void
    {
        $record = $this->tokens->findActiveByHash(Token::hash($rawToken));
        if ($record === null) {
            return;
        }
        $this->tokens->revoke($record->tokenId);
        $this->audit->record('auth.logout', $record->userId, $record->tenantId);
    }

    public function revokeAllForUser(string $userId): int
    {
        $count = $this->tokens->revokeAllForUser($userId);
        $this->audit->record('auth.logout_all', $userId, null, ['revoked' => $count]);

        return $count;
    }

    private function expiry(int $ttlSeconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->add(new \DateInterval('PT' . max(60, $ttlSeconds) . 'S'));
    }
}
