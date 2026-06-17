<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

final class CategoryMetaRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
        $this->ensureSchema();
    }

    public function get(string $editionId, string $categoryId, string $key): mixed
    {
        try {
            $row = $this->db->queryOne(
                'SELECT value FROM vote_category_meta
                 WHERE edition_id = :ed_id AND category_id = :cat_id AND key = :key',
                ['ed_id' => $editionId, 'cat_id' => $categoryId, 'key' => $key],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to get category meta [$key].", layer: 'repository.voting.meta', previous: $e);
        }
        return $row === null ? null : json_decode($row['value'], true);
    }

    public function set(string $editionId, string $categoryId, string $key, mixed $value): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_category_meta (edition_id, category_id, key, value)
                 VALUES (:ed_id, :cat_id, :key, :value)
                 ON CONFLICT(edition_id, category_id, key) DO UPDATE SET value = :value',
                ['ed_id' => $editionId, 'cat_id' => $categoryId, 'key' => $key, 'value' => json_encode($value)],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to set category meta [$key].", layer: 'repository.voting.meta', previous: $e);
        }
    }

    public function delete(string $editionId, string $categoryId, string $key): void
    {
        try {
            $this->db->execute(
                'DELETE FROM vote_category_meta
                 WHERE edition_id = :ed_id AND category_id = :cat_id AND key = :key',
                ['ed_id' => $editionId, 'cat_id' => $categoryId, 'key' => $key],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to delete category meta [$key].", layer: 'repository.voting.meta', previous: $e);
        }
    }

    private function ensureSchema(): void
    {
        if (self::$schemaInitialized) {
            return;
        }
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS vote_category_meta (
                    edition_id  TEXT NOT NULL,
                    category_id TEXT NOT NULL,
                    key         TEXT NOT NULL,
                    value       TEXT NOT NULL DEFAULT \'{}\',
                    PRIMARY KEY (edition_id, category_id, key)
                )',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to init vote_category_meta schema.', layer: 'repository.voting.meta', previous: $e);
        }
    }
}
