<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

/**
 * Client — a registered OAuth2 application (RFC 6749 §2).
 *
 * Confidential clients hold a hashed secret and may use any grant; public
 * clients (SPA/mobile) have no secret and MUST use Authorization Code + PKCE.
 * Redirect URIs are matched EXACTLY (no wildcard / prefix) per OAuth 2.1.
 */
final class Client
{
    /**
     * @param list<string> $redirectUris  Exact-match allowed redirect targets.
     * @param list<string> $grantTypes    Grant type strings this client may use.
     * @param list<string> $scopes        Scopes this client may request (empty = any registered scope).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $secretHash, // null = public client
        public readonly array $redirectUris,
        public readonly array $grantTypes,
        public readonly array $scopes,
        public readonly bool $confidential,
        public readonly bool $revoked = false,
    ) {
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
