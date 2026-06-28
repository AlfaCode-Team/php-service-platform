<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;

/**
 * IntrospectionService — RFC 7662 token introspection + RFC 7009 revocation.
 *
 * Access tokens are self-describing JWTs, so introspection verifies the
 * signature/expiry locally. Refresh tokens are opaque and looked up by hash.
 * Returns the RFC 7662 response shape; an inactive/invalid token always returns
 * `{"active": false}` with no other detail (no information leak).
 */
final class IntrospectionService
{
    /** Mirrors Plugins\Auth\Security\JwtAuthLayer's deny-list key (kept inline to avoid coupling). */
    private const JWT_REVOCATION_PREFIX = 'auth:jwt:revoked:';

    public function __construct(
        private readonly RefreshTokenStore $refreshTokens,
        private readonly TokenIssuer $issuer,
        private readonly string $verifyKey,  // HS secret or PEM public key
        private readonly string $algo,
        private readonly ?CachePort $revocations = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function introspect(string $token): array
    {
        if ($token === '') {
            return ['active' => false];
        }

        // 1. Try as a JWT access token.
        try {
            $claims = (array) JWT::decode($token, new Key($this->verifyKey, $this->algo));

            return [
                'active'     => true,
                'token_type' => 'access_token',
                'scope'      => $claims['scope'] ?? '',
                'client_id'  => $claims['client_id'] ?? null,
                'sub'        => $claims['sub'] ?? null,
                'exp'        => $claims['exp'] ?? null,
                'iat'        => $claims['iat'] ?? null,
                'iss'        => $claims['iss'] ?? null,
                'aud'        => $claims['aud'] ?? null,
                'jti'        => $claims['jti'] ?? null,
            ];
        } catch (\Throwable) {
            // not a (valid) JWT — fall through to opaque refresh lookup
        }

        // 2. Try as an opaque refresh token.
        $record = $this->refreshTokens->findByHash($this->issuer->hash($token));
        if ($record !== null && !$record->revoked && !$record->isExpired()) {
            return [
                'active'     => true,
                'token_type' => 'refresh_token',
                'scope'      => implode(' ', $record->scopes),
                'client_id'  => $record->clientId,
                'sub'        => $record->userId,
                'exp'        => $record->expiresAt->getTimestamp(),
            ];
        }

        return ['active' => false];
    }

    /**
     * RFC 7009 revocation. Handles BOTH token types:
     *   - opaque refresh token → revoke the whole rotation family;
     *   - JWT access token     → deny-list its `jti` (same list the platform
     *     JwtAuthLayer consults), so it stops authenticating before its natural
     *     expiry.
     * Per the RFC, an unknown/unsupported token still returns success.
     */
    public function revoke(string $token): void
    {
        if ($token === '') {
            return;
        }

        // Access token (JWT): deny-list the jti until it would have expired.
        try {
            $claims = (array) JWT::decode($token, new Key($this->verifyKey, $this->algo));
            $jti = (string) ($claims['jti'] ?? '');
            if ($jti !== '' && $this->revocations !== null) {
                $ttl = max(1, (int) ($claims['exp'] ?? 0) - time());
                $this->revocations->set(self::JWT_REVOCATION_PREFIX . $jti, 1, $ttl);
            }

            return;
        } catch (\Throwable) {
            // not a JWT — treat as an opaque refresh token below
        }

        $record = $this->refreshTokens->findByHash($this->issuer->hash($token));
        if ($record !== null) {
            $this->refreshTokens->revokeFamily($record->familyId);
        }
    }
}
