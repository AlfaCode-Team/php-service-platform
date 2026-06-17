<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\Domain\Entities\Contestant;

final class ContestantRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity     $identity,
    ) {
        $this->ensureSchema();
    }

    public function find(string $id): ?Contestant
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, edition_id, organiser_id, full_name, slug, avatar_id, detail, category_id, vote_count, boost_count, registered_at
                 FROM vote_contestants
                 WHERE id = :id AND tenant_id = :tenant',
                ['id' => $id, 'tenant' => $this->identity->tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load contestant [{$id}].",
                layer:   'repository.voting.contestant',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<Contestant> */
    public function findByEdition(string $editionId, ?string $categoryId = null): array
    {
        $sql    = 'SELECT id, edition_id, organiser_id, full_name, slug, avatar_id, detail, category_id, vote_count, boost_count, registered_at
                   FROM vote_contestants
                   WHERE edition_id = :edition_id AND tenant_id = :tenant';
        $params = ['edition_id' => $editionId, 'tenant' => $this->identity->tenantId];

        if ($categoryId !== null && $categoryId !== '') {
            $sql              .= ' AND category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $sql .= ' ORDER BY vote_count DESC';

        try {
            $rows = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to list contestants for edition [{$editionId}].",
                layer:   'repository.voting.contestant',
                context: ['edition_id' => $editionId],
                previous: $e,
            );
        }

        return array_map(static fn(array $row): Contestant => self::hydrate($row), $rows);
    }

    public function save(Contestant $contestant): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_contestants
                    (id, tenant_id, edition_id, organiser_id, full_name, slug, avatar_id, detail, category_id, vote_count, boost_count, registered_at)
                 VALUES
                    (:id, :tenant, :edition_id, :organiser_id, :full_name, :slug, :avatar_id, :detail, :category_id, :vote_count, :boost_count, :registered_at)
                 ON CONFLICT(id) DO UPDATE SET
                    full_name   = :full_name,
                    avatar_id   = :avatar_id,
                    detail      = :detail,
                    category_id = :category_id,
                    vote_count  = :vote_count,
                    boost_count = :boost_count',
                [
                    'id'            => $contestant->id()->value(),
                    'tenant'        => $this->identity->tenantId,
                    'edition_id'    => $contestant->editionId()->value(),
                    'organiser_id'  => $contestant->organiserId(),
                    'full_name'     => $contestant->fullName(),
                    'slug'          => $contestant->slug(),
                    'avatar_id'     => $contestant->avatarId(),
                    'detail'        => $contestant->detail(),
                    'category_id'   => $contestant->categoryId(),
                    'vote_count'    => $contestant->voteCount()->value(),
                    'boost_count'   => $contestant->boostCount(),
                    'registered_at' => $contestant->registeredAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save contestant [{$contestant->id()->value()}].",
                layer:    'repository.voting.contestant',
                context:  ['id' => $contestant->id()->value()],
                previous: $e,
            );
        }
    }

    public function atomicIncrementVoteCount(string $id, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }
        try {
            $this->db->execute(
                'UPDATE vote_contestants SET vote_count = vote_count + :by
                 WHERE id = :id AND tenant_id = :tenant',
                ['by' => $by, 'id' => $id, 'tenant' => $this->identity->tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to increment vote count for contestant [{$id}].",
                layer:   'repository.voting.contestant',
                context: ['id' => $id],
                previous: $e,
            );
        }
    }

    public function atomicIncrementBoostCount(string $id, int $by): void
    {
        if ($by < 1) {
            return;
        }
        try {
            $this->db->execute(
                'UPDATE vote_contestants SET boost_count = boost_count + :by
                 WHERE id = :id AND tenant_id = :tenant',
                ['by' => $by, 'id' => $id, 'tenant' => $this->identity->tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to increment boost count for contestant [{$id}].",
                layer:   'repository.voting.contestant',
                context: ['id' => $id],
                previous: $e,
            );
        }
    }

    public function countByEdition(string $editionId, ?string $categoryId = null): int
    {
        $sql    = 'SELECT COUNT(*) AS cnt FROM vote_contestants
                   WHERE edition_id = :edition_id AND tenant_id = :tenant';
        $params = ['edition_id' => $editionId, 'tenant' => $this->identity->tenantId];

        if ($categoryId !== null && $categoryId !== '') {
            $sql                .= ' AND category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        try {
            $row = $this->db->queryOne($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to count contestants for edition [{$editionId}].",
                layer:   'repository.voting.contestant',
                context: ['edition_id' => $editionId],
                previous: $e,
            );
        }

        return (int) ($row['cnt'] ?? 0);
    }

    public function delete(string $id): bool
    {
        try {
            $affected = $this->db->execute(
                'DELETE FROM vote_contestants WHERE id = :id AND tenant_id = :tenant',
                ['id' => $id, 'tenant' => $this->identity->tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to delete contestant [{$id}].",
                layer:   'repository.voting.contestant',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return $affected > 0;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaInitialized) {
            return;
        }
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS vote_contestants (
                    id            TEXT NOT NULL PRIMARY KEY,
                    tenant_id     TEXT NOT NULL DEFAULT \'\',
                    edition_id    TEXT NOT NULL,
                    organiser_id  TEXT NOT NULL DEFAULT \'\',
                    full_name     TEXT NOT NULL,
                    slug          TEXT NOT NULL,
                    avatar_id     TEXT NOT NULL DEFAULT \'\',
                    detail        TEXT NOT NULL DEFAULT \'\',
                    category_id   TEXT NOT NULL DEFAULT \'\',
                    vote_count    INTEGER NOT NULL DEFAULT 0,
                    boost_count   INTEGER NOT NULL DEFAULT 0,
                    registered_at TEXT NOT NULL
                )',
            );
            // Covering index for leaderboard queries (ORDER BY vote_count DESC)
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_contestants_leaderboard
                 ON vote_contestants(edition_id, tenant_id, vote_count)',
            );
            // Covering index for category-filtered leaderboard
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_contestants_category
                 ON vote_contestants(edition_id, category_id, vote_count)',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_contestants schema.',
                layer:    'repository.voting.contestant',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Contestant
    {
        return Contestant::reconstitute(
            id:           (string) $row['id'],
            editionId:    (string) $row['edition_id'],
            organiserId:  (string) $row['organiser_id'],
            fullName:     (string) $row['full_name'],
            slug:         (string) $row['slug'],
            avatarId:     (string) $row['avatar_id'],
            detail:       (string) $row['detail'],
            categoryId:   (string) ($row['category_id'] ?? ''),
            voteCount:    (int)    $row['vote_count'],
            boostCount:   (int)    ($row['boost_count'] ?? 0),
            registeredAt: (string) $row['registered_at'],
        );
    }
}
