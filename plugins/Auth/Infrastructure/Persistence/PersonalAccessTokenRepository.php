<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;

/**
 * Persists personal access tokens (hashed) via DatabasePort only.
 *
 * Table: personal_access_tokens(id, user_id, name, token_hash, last_used_at, created_at)
 */
final class PersonalAccessTokenRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly string $table = 'personal_access_tokens',
    ) {
    }

    /**
     * @param list<string> $abilities Scope list; null/[] = no abilities granted.
     * @param \DateTimeImmutable|null $expiresAt Absolute expiry; null = never expires.
     */
    public function store(
        string $id,
        string $userId,
        string $name,
        string $tokenHash,
        array $abilities = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): void {
        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (id, user_id, name, token_hash, abilities, expires_at, created_at)
                 VALUES (:id, :user_id, :name, :token_hash, :abilities, :expires_at, :created_at)",
                [
                    'id'         => $id,
                    'user_id'    => $userId,
                    'name'       => $name,
                    'token_hash' => $tokenHash,
                    'abilities'  => $abilities === [] ? null : json_encode(array_values($abilities)),
                    'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to store access token', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * Look up an UNEXPIRED token by its hash. Expired tokens are treated as
     * absent so a stale credential can never authenticate.
     *
     * @return array{id:string,user_id:string,abilities:list<string>}|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        try {
            $row = $this->db->queryOne(
                "SELECT id, user_id, abilities, expires_at FROM {$this->table} WHERE token_hash = :hash",
                ['hash' => $tokenHash]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to look up access token', layer: 'repository.auth', previous: $e);
        }

        if ($row === null) {
            return null;
        }

        // Enforce expiry in PHP (driver-portable — no NOW() dialect branching).
        $expiresAt = $row['expires_at'] ?? null;
        if ($expiresAt !== null && $expiresAt !== '' && strtotime((string) $expiresAt) <= time()) {
            return null;
        }

        $abilities = [];
        if (!empty($row['abilities'])) {
            $decoded = json_decode((string) $row['abilities'], true);
            if (is_array($decoded)) {
                $abilities = array_values(array_filter($decoded, 'is_string'));
            }
        }

        return [
            'id'        => (string) $row['id'],
            'user_id'   => (string) $row['user_id'],
            'abilities' => $abilities,
        ];
    }

    /** Stamp the token's last-use time (best-effort observability/anomaly detection). */
    public function touch(string $id): void
    {
        try {
            $this->db->execute(
                "UPDATE {$this->table} SET last_used_at = :now WHERE id = :id",
                ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id]
            );
        } catch (\PDOException) {
            // Non-fatal — last_used_at is observability metadata, not an auth gate.
        }
    }

    public function delete(string $id): void
    {
        try {
            $this->db->execute("DELETE FROM {$this->table} WHERE id = :id", ['id' => $id]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke access token', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * Delete every token whose absolute expiry has already passed. Returns the
     * number of rows removed. Driver-portable — binds the cutoff rather than
     * relying on a dialect-specific NOW().
     */
    public function deleteExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            return $this->db->execute(
                "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at <= :cutoff",
                ['cutoff' => $cutoff]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to prune expired access tokens', layer: 'repository.auth', previous: $e);
        }
    }

    /** Count tokens whose expiry has passed (drives the prune command's --dry mode). */
    public function countExpired(?\DateTimeImmutable $now = null): int
    {
        $cutoff = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $row = $this->db->queryOne(
                "SELECT COUNT(*) AS n FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at <= :cutoff",
                ['cutoff' => $cutoff]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count expired access tokens', layer: 'repository.auth', previous: $e);
        }

        return (int) ($row['n'] ?? 0);
    }
}
