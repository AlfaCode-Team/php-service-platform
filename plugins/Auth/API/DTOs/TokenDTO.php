<?php

declare(strict_types=1);

namespace Plugins\Auth\API\DTOs;

/**
 * TokenDTO — a safe, published view of a personal access token record.
 *
 * Carries NO secret material (the hash never leaves the repository). This is the
 * GDA-native replacement for the old HasApiTokens `tokens()` collection: instead
 * of hydrating Eloquent models on a user object, the AuthServiceContract returns
 * these value objects keyed by user id.
 */
final readonly class TokenDTO
{
    /**
     * @param list<string> $abilities
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $abilities,
        public ?\DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public ?\DateTimeImmutable $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $abilities = $row['abilities'] ?? [];
        if (is_string($abilities)) {
            $decoded   = json_decode($abilities, true);
            $abilities = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id:         (string) ($row['id'] ?? ''),
            name:       (string) ($row['name'] ?? 'default'),
            abilities:  array_values(array_filter((array) $abilities, 'is_string')),
            expiresAt:  self::date($row['expires_at'] ?? null),
            lastUsedAt: self::date($row['last_used_at'] ?? null),
            createdAt:  self::date($row['created_at'] ?? null),
        );
    }

    /**
     * True when the token carries the given ability — hierarchical, so `admin`
     * satisfies `admin:write`, and `*` grants everything.
     */
    public function can(string $ability): bool
    {
        return \Plugins\Auth\API\ScopeInheritance::satisfies($this->abilities, $ability);
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt !== null
            && $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'abilities'    => $this->abilities,
            'expires_at'   => $this->expiresAt?->format(\DateTimeInterface::RFC3339),
            'last_used_at' => $this->lastUsedAt?->format(\DateTimeInterface::RFC3339),
            'created_at'   => $this->createdAt?->format(\DateTimeInterface::RFC3339),
        ];
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }
}
