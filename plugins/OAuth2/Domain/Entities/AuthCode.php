<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * AuthCode — a short-lived, single-use authorization code (RFC 6749 §4.1).
 *
 * Bound to the client, the exact redirect URI, the granted scopes, the
 * authenticated user, and (for PKCE) the code challenge. Only the SHA-256 of the
 * code is stored; the raw code is shown to the client once via the redirect.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 */
final class AuthCode extends Entity
{
    /** @param list<string> $scopes */
    public static function of(
        string $id,
        string $clientId,
        string $userId,
        string $redirectUri,
        array $scopes,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        \DateTimeImmutable $expiresAt,
        bool $consumed = false,
        ?string $nonce = null,
    ): self {
        $c = (new self())->forceFill([
            'id'                  => $id,
            'clientId'            => $clientId,
            'userId'              => $userId,
            'redirectUri'         => $redirectUri,
            'scopes'              => $scopes,
            'codeChallenge'       => $codeChallenge,
            'codeChallengeMethod' => $codeChallengeMethod,
            'expiresAt'           => $expiresAt,
            'consumed'            => $consumed,
            'nonce'               => $nonce,
        ]);
        $c->syncOriginal();

        return $c;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
