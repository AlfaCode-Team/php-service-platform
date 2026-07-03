<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * RefreshToken — a revocable, rotating long-lived credential (RFC 6749 §6).
 *
 * Stored hashed. Rotation is one-time-use: redeeming a refresh token revokes it
 * and issues a successor in the same family, so a replayed (already-rotated)
 * token signals theft and triggers family-wide revocation.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 */
final class RefreshToken extends Entity
{
    /** @param list<string> $scopes */
    public static function of(
        string $id,
        string $familyId,
        string $clientId,
        string $userId,
        array $scopes,
        \DateTimeImmutable $expiresAt,
        bool $revoked = false,
    ): self {
        $t = (new self())->forceFill([
            'id'        => $id,
            'familyId'  => $familyId,
            'clientId'  => $clientId,
            'userId'    => $userId,
            'scopes'    => $scopes,
            'expiresAt' => $expiresAt,
            'revoked'   => $revoked,
        ]);
        $t->syncOriginal();

        return $t;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
