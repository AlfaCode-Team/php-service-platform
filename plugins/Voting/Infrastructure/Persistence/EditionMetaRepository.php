<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

final class EditionMetaRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
        $this->ensureSchema();
    }

    public function get(string $editionId, string $key): mixed
    {
        try {
            $row = $this->db->queryOne(
                'SELECT value FROM vote_edition_meta
                 WHERE edition_id = :id AND key = :key',
                ['id' => $editionId, 'key' => $key],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to get edition meta [$key].", layer: 'repository.voting.meta', previous: $e);
        }
        return $row === null ? null : json_decode($row['value'], true);
    }

    public function set(string $editionId, string $key, mixed $value): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_edition_meta (edition_id, key, value)
                 VALUES (:id, :key, :value)
                 ON CONFLICT(edition_id, key) DO UPDATE SET value = :value',
                ['id' => $editionId, 'key' => $key, 'value' => json_encode($value)],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to set edition meta [$key].", layer: 'repository.voting.meta', previous: $e);
        }
    }

    public function delete(string $editionId, string $key): void
    {
        try {
            $this->db->execute(
                'DELETE FROM vote_edition_meta WHERE edition_id = :id AND key = :key',
                ['id' => $editionId, 'key' => $key],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to delete edition meta [$key].", layer: 'repository.voting.meta', previous: $e);
        }
    }

    private function ensureSchema(): void
    {
        if (self::$schemaInitialized) {
            return;
        }
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS vote_edition_meta (
                    edition_id TEXT NOT NULL,
                    key        TEXT NOT NULL,
                    value      TEXT NOT NULL DEFAULT \'{}\',
                    PRIMARY KEY (edition_id, key)
                )',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to init vote_edition_meta schema.', layer: 'repository.voting.meta', previous: $e);
        }
    }
}
