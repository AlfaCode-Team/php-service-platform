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

    public function store(string $id, string $userId, string $name, string $tokenHash): void
    {
        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (id, user_id, name, token_hash, created_at)
                 VALUES (:id, :user_id, :name, :token_hash, :created_at)",
                [
                    'id'         => $id,
                    'user_id'    => $userId,
                    'name'       => $name,
                    'token_hash' => $tokenHash,
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to store access token', layer: 'repository.auth', previous: $e);
        }
    }

    /**
     * @return array{id:string,user_id:string}|null
     */
    public function findByHash(string $tokenHash): ?array
    {
        try {
            $row = $this->db->queryOne(
                "SELECT id, user_id FROM {$this->table} WHERE token_hash = :hash",
                ['hash' => $tokenHash]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to look up access token', layer: 'repository.auth', previous: $e);
        }

        return $row === null ? null : ['id' => (string) $row['id'], 'user_id' => (string) $row['user_id']];
    }

    public function delete(string $id): void
    {
        try {
            $this->db->execute("DELETE FROM {$this->table} WHERE id = :id", ['id' => $id]);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to revoke access token', layer: 'repository.auth', previous: $e);
        }
    }
}
