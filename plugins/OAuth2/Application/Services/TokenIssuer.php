<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use Firebase\JWT\JWT;

/**
 * TokenIssuer — mints OAuth2 access tokens as signed JWTs and opaque refresh
 * tokens.
 *
 * Access tokens are signed with the SAME key/algorithm the platform's
 * JwtAuthLayer verifies, so a resource server needs no OAuth-specific code: an
 * OAuth2 Bearer token is validated by the existing security layer. Scopes are
 * Scopes are published as the RFC `scope` claim AND, NAMESPACED with a `scope:`
 * prefix, into `permissions` (→ Identity.permissions). The prefix keeps OAuth
 * scopes from ever colliding with first-party RBAC permission names: a delegated
 * token can never satisfy an internal `hasPermission('admin')` check.
 */
final class TokenIssuer
{
    public function __construct(
        private readonly string $algo,
        private readonly string $secret,          // HS* secret
        private readonly ?string $privateKey,     // RS*/ES*/PS* PEM
        private readonly ?string $issuer,
        private readonly ?string $keyId,
        private readonly int $accessTtl = 3600,
        /** Resource-server audience for access tokens; null → audience is the client_id. */
        private readonly ?string $audience = null,
    ) {
    }

    /** Asymmetric signing (RS/ES/PS) → the id_token is verifiable by public clients. */
    public function isAsymmetric(): bool
    {
        $c = $this->algo[0] ?? 'H';
 
        return $c === 'R' || $c === 'E' || $c === 'P';
    }

    /**
     * @param list<string> $scopes
     * @return array{token:string, jti:string, expires_in:int}
     */
    public function accessToken(string $subject, string $clientId, array $scopes, string $tenantId = ''): array
    {
        $now = time();
        $jti = bin2hex(random_bytes(16));

        // Audience is the RESOURCE SERVER (so platform JwtAuthLayer audience checks
        // pass); `azp` records the authorized client. Falls back to client_id when
        // no resource audience is configured.
        $audience = ($this->audience !== null && $this->audience !== '') ? $this->audience : $clientId;

        $payload = array_filter([
            'iss'         => $this->issuer,
            'aud'         => $audience,
            'azp'         => $clientId,
            'sub'         => $subject,
            'client_id'   => $clientId,
            'scope'       => implode(' ', $scopes),
            // Namespaced so OAuth scopes can never be mistaken for RBAC permissions.
            'permissions' => array_map(static fn (string $s): string => 'scope:' . $s, array_values($scopes)),
            'tnt'         => $tenantId,
            'iat'         => $now,
            'nbf'         => $now,
            'exp'         => $now + $this->accessTtl,
            'jti'         => $jti,
        ], static fn ($v) => $v !== null);

        $key = $this->isAsymmetric() ? (string) $this->privateKey : $this->secret;
        $kid = ($this->keyId !== null && $this->keyId !== '') ? $this->keyId : null;

        return [
            'token'      => JWT::encode($payload, $key, $this->algo, $kid),
            'jti'        => $jti,
            'expires_in' => $this->accessTtl,
        ];
    }

    /**
     * Mint an OpenID Connect id_token (OIDC Core §2) — issued when the `openid`
     * scope is granted. Signed with the same key as access tokens.
     */
    public function idToken(string $subject, string $clientId, ?string $nonce = null, ?int $authTime = null): string
    {
        $now = time();
        $payload = array_filter([
            'iss'       => $this->issuer,
            'sub'       => $subject,
            'aud'       => $clientId,
            'iat'       => $now,
            'exp'       => $now + $this->accessTtl,
            'auth_time' => $authTime,
            'nonce'     => $nonce,
        ], static fn ($v) => $v !== null);

        $key = $this->isAsymmetric() ? (string) $this->privateKey : $this->secret;
        $kid = ($this->keyId !== null && $this->keyId !== '') ? $this->keyId : null;

        return JWT::encode($payload, $key, $this->algo, $kid);
    }

    /** A cryptographically-random opaque refresh token (raw — store only its hash). */
    public function refreshToken(): string
    {
        return bin2hex(random_bytes(40));
    }

    public function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public function accessTtl(): int
    {
        return $this->accessTtl;
    }
}
