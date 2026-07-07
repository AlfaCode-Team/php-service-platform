<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * Client — a registered OAuth2 application (RFC 6749 §2).
 *
 * Confidential clients hold a hashed secret and may use any grant; public
 * clients (SPA/mobile) have no secret and MUST use Authorization Code + PKCE.
 * Redirect URIs are matched EXACTLY (no wildcard / prefix) per OAuth 2.1.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag). The
 * hashed secret is $hidden so it never leaks into dumps/serialization.
 */
final class Client extends Entity
{
    protected array $hidden = ['secretHash'];

    /**
     * @param list<string> $redirectUris  Exact-match allowed redirect targets.
     * @param list<string> $grantTypes    Grant type strings this client may use.
     * @param list<string> $scopes        Scopes this client may request (empty = any registered scope).
     */
    public static function of(
        string $id,
        string $name,
        ?string $secretHash,
        array $redirectUris,
        array $grantTypes,
        array $scopes,
        bool $confidential,
        bool $revoked = false,
        ?string $ownerId = null,
    ): self {
        $c = (new self())->forceFill([
            'id'           => $id,
            'name'         => $name,
            'secretHash'   => $secretHash,
            'redirectUris' => $redirectUris,
            'grantTypes'   => $grantTypes,
            'scopes'       => $scopes,
            'confidential' => $confidential,
            'revoked'      => $revoked,
            'ownerId'      => $ownerId,
        ]);
        $c->syncOriginal();

        return $c;
    }

    /** The user_id that registered this client, or null for a first-party client. */
    public function ownerId(): ?string
    {
        $owner = $this->ownerId ?? null;

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /** A secret-free public view for the self-service management API. */
    public function toPublicArray(): array
    {
        return [
            'id'            => (string) $this->id,
            'name'          => (string) $this->name,
            'redirect_uris' => $this->redirectUris ?? [],
            'grant_types'   => $this->grantTypes ?? [],
            'scopes'        => $this->scopes ?? [],
            'confidential'  => (bool) $this->confidential,
            'revoked'       => (bool) $this->revoked,
        ];
    }

    public function isPublic(): bool
    {
        return !$this->confidential || $this->secretHash === null;
    }

    /** Exact-match redirect URI check (OAuth 2.1 — no wildcards). */
    public function allowsRedirect(string $uri): bool
    {
        foreach ($this->redirectUris as $allowed) {
            if (hash_equals($allowed, $uri)) {
                return true;
            }
        }

        return false;
    }

    public function allowsGrant(string $grantType): bool
    {
        return in_array($grantType, $this->grantTypes, true);
    }

    /** The first registered redirect URI (used when the request omits one and exactly one is registered). */
    public function defaultRedirect(): ?string
    {
        return $this->redirectUris[0] ?? null;
    }
}
