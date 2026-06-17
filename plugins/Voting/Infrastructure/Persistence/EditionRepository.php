<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\Domain\Entities\Edition;
use Plugins\Voting\Domain\ValueObjects\EditionStatus;

final class EditionRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity     $identity,
    ) {
        $this->ensureSchema();
    }

    public function find(string $id): ?Edition
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, title, slug, organiser_id, status, start_date, end_date, created_at
                 FROM vote_editions
                 WHERE id = :id AND tenant_id = :tenant',
                ['id' => $id, 'tenant' => $this->identity->tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load edition [{$id}].",
                layer:   'repository.voting.edition',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<Edition> */
    public function findActive(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT id, title, slug, organiser_id, status, start_date, end_date, created_at
                 FROM vote_editions
                 WHERE tenant_id = :tenant AND status != :closed
                 ORDER BY created_at DESC',
                ['tenant' => $this->identity->tenantId, 'closed' => EditionStatus::Closed->value],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to list editions.',
                layer:    'repository.voting.edition',
                previous: $e,
            );
        }

        return array_map(static fn(array $row): Edition => self::hydrate($row), $rows);
    }

    public function save(Edition $edition): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_editions
                    (id, tenant_id, title, slug, organiser_id, status, start_date, end_date, created_at)
                 VALUES
                    (:id, :tenant, :title, :slug, :organiser_id, :status, :start_date, :end_date, :created_at)
                 ON CONFLICT(id) DO UPDATE SET
                    title       = :title,
                    status      = :status,
                    start_date  = :start_date,
                    end_date    = :end_date',
                [
                    'id'           => $edition->id()->value(),
                    'tenant'       => $this->identity->tenantId,
                    'title'        => $edition->title(),
                    'slug'         => $edition->slug(),
                    'organiser_id' => $edition->organiserId(),
                    'status'       => $edition->status()->value,
                    'start_date'   => $edition->startDate()?->format(\DateTimeInterface::RFC3339),
                    'end_date'     => $edition->endDate()?->format(\DateTimeInterface::RFC3339),
                    'created_at'   => $edition->createdAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save edition [{$edition->id()->value()}].",
                layer:    'repository.voting.edition',
                context:  ['id' => $edition->id()->value()],
                previous: $e,
            );
        }
    }

    private function ensureSchema(): void
    {
        if (self::$schemaInitialized) {
            return;
        }
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS vote_editions (
                    id           TEXT NOT NULL PRIMARY KEY,
                    tenant_id    TEXT NOT NULL DEFAULT \'\',
                    title        TEXT NOT NULL,
                    slug         TEXT NOT NULL,
                    organiser_id TEXT NOT NULL DEFAULT \'\',
                    status       TEXT NOT NULL DEFAULT \'draft\',
                    start_date   TEXT,
                    end_date     TEXT,
                    created_at   TEXT NOT NULL
                )',
            );
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_editions_tenant_status
                 ON vote_editions(tenant_id, status)',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_editions schema.',
                layer:    'repository.voting.edition',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Edition
    {
        return Edition::reconstitute(
            id:          (string) $row['id'],
            title:       (string) $row['title'],
            slug:        (string) $row['slug'],
            organiserId: (string) $row['organiser_id'],
            status:      (string) $row['status'],
            startDate:   isset($row['start_date']) ? (string) $row['start_date'] : null,
            endDate:     isset($row['end_date'])   ? (string) $row['end_date']   : null,
            createdAt:   (string) $row['created_at'],
        );
    }
}
