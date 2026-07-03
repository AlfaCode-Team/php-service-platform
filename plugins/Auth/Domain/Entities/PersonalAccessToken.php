<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * PersonalAccessToken — a hashed, optionally-scoped, optionally-expiring API
 * credential (table `personal_access_tokens`).
 *
 * Only the SHA-256 of the token is ever stored; the plaintext is shown to the
 * caller once at issuance and never persisted. Built on the shared {@see Entity}
 * attribute-bag base, keyed by DB column. The hash is $hidden so it never leaks
 * into dumps/serialization.
 */
final class PersonalAccessToken extends Entity
{
    protected string $primaryKey = 'id';

    /** @var array<string, string> */
    protected array $casts = [
        // Entity short-circuits casts on null, so plain datetime casts are safe
        // for the nullable expiry/last-used columns.
        'expires_at'   => 'datetime',
        'last_used_at' => 'datetime',
        'created_at'   => 'datetime',
    ];

    /** The token hash never appears in dumps/serialization. */
    protected array $hidden = ['token_hash'];

    /**
     * Mint a new token record. $tokenHash MUST already be the SHA-256 of the
     * plaintext — this entity never sees the raw token.
     *
     * @param list<string>             $abilities Scope list; [] = no abilities granted.
     * @param \DateTimeImmutable|null  $expiresAt Absolute expiry; null = never expires.
     */
    public static function issue(
        string $id,
        string $userId,
        string $name,
        string $tokenHash,
        array $abilities = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): self {
        $t = (new self())->forceFill([
            'id'           => $id,
            'user_id'      => $userId,
            'name'         => $name,
            'token_hash'   => $tokenHash,
            'abilities'    => array_values($abilities),
            'expires_at'   => $expiresAt,
            'last_used_at' => null,
            'created_at'   => new \DateTimeImmutable(),
        ]);
        $t->syncOriginal();

        return $t;
    }

    public function id(): string        { return $this->getString('id'); }
    public function userId(): string    { return $this->getString('user_id'); }
    public function name(): string      { return $this->getString('name'); }
    public function tokenHash(): string { return $this->getString('token_hash'); }

    /** @return list<string> */
    public function abilities(): array
    {
        return array_values(array_filter($this->getArray('abilities'), 'is_string'));
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->getRawAttribute('expires_at') === null ? null : $this->getDate('expires_at');
    }

    /** Expired tokens are treated as absent so a stale credential never authenticates. */
    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** Persistence-shaped abilities column: a JSON array, or null when empty. */
    public function abilitiesColumn(): ?string
    {
        $abilities = $this->abilities();

        return $abilities === [] ? null : json_encode($abilities);
    }
}
