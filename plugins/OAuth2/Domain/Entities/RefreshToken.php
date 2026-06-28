<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

/**
 * RefreshToken — a revocable, rotating long-lived credential (RFC 6749 §6).
 *
 * Stored hashed. Rotation is one-time-use: redeeming a refresh token revokes it
 * and issues a successor in the same family, so a replayed (already-rotated)
 * token signals theft and triggers family-wide revocation.
 */
final class RefreshToken
{
    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly string $familyId,
        public readonly string $clientId,
        public readonly string $userId,
        public readonly array $scopes,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly bool $revoked = false,
    ) {
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
