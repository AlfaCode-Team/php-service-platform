<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

/**
 * AuthCode — a short-lived, single-use authorization code (RFC 6749 §4.1).
 *
 * Bound to the client, the exact redirect URI, the granted scopes, the
 * authenticated user, and (for PKCE) the code challenge. Only the SHA-256 of the
 * code is stored; the raw code is shown to the client once via the redirect.
 */
final class AuthCode
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly string $clientId,
        public readonly string $userId,
        public readonly string $redirectUri,
        public readonly array $scopes,
        public readonly ?string $codeChallenge,
        public readonly ?string $codeChallengeMethod,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly bool $consumed = false,
        public readonly ?string $nonce = null,
    ) {
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
