<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Voting\Domain\Entities\VoteRecord;

final class VoteRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
        $this->ensureSchema();
    }

    public function findByUserAndContestant(string $userId, string $contestantId): ?VoteRecord
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, contestant_id, edition_id, user_id, ip_address, vote_count,
                        can_vote_again_at, created_at, updated_at
                 FROM vote_records
                 WHERE user_id = :user_id AND contestant_id = :contestant_id',
                ['user_id' => $userId, 'contestant_id' => $contestantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load vote record for user [{$userId}] contestant [{$contestantId}].",
                layer:   'repository.voting.record',
                context: ['user_id' => $userId, 'contestant_id' => $contestantId],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function countRecentByIp(string $ip, int $windowSeconds): int
    {
        if ($ip === '') {
            return 0;
        }

        try {
            $since = (new \DateTimeImmutable())->modify("-{$windowSeconds} seconds")
                ->format(\DateTimeInterface::RFC3339);

            $row = $this->db->queryOne(
                'SELECT COUNT(*) AS cnt FROM vote_records
                 WHERE ip_address = :ip AND updated_at > :since',
                ['ip' => $ip, 'since' => $since],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to count recent votes by IP.',
                layer:    'repository.voting.record',
                previous: $e,
            );
        }

        return (int) ($row['cnt'] ?? 0);
    }

    public function save(VoteRecord $record): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_records
                    (id, contestant_id, edition_id, user_id, ip_address, vote_count, can_vote_again_at, created_at, updated_at)
                 VALUES
                    (:id, :contestant_id, :edition_id, :user_id, :ip_address, :vote_count, :can_vote_again_at, :created_at, :updated_at)
                 ON CONFLICT(user_id, contestant_id) DO UPDATE SET
                    ip_address        = :ip_address,
                    vote_count        = :vote_count,
                    can_vote_again_at = :can_vote_again_at,
                    updated_at        = :updated_at',
                [
                    'id'               => $record->id(),
                    'contestant_id'    => $record->contestantId()->value(),
                    'edition_id'       => $record->editionId()->value(),
                    'user_id'          => $record->userId(),
                    'ip_address'       => $record->ipAddress(),
                    'vote_count'       => $record->voteCount()->value(),
                    'can_vote_again_at'=> $record->canVoteAgainAt()->format(\DateTimeInterface::RFC3339),
                    'created_at'       => $record->createdAt()->format(\DateTimeInterface::RFC3339),
                    'updated_at'       => $record->updatedAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save vote record [{$record->id()}].",
                layer:    'repository.voting.record',
                context:  ['id' => $record->id()],
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
                'CREATE TABLE IF NOT EXISTS vote_records (
                    id                TEXT NOT NULL PRIMARY KEY,
                    contestant_id     TEXT NOT NULL,
                    edition_id        TEXT NOT NULL,
                    user_id           TEXT NOT NULL,
                    ip_address        TEXT NOT NULL DEFAULT \'\',
                    vote_count        INTEGER NOT NULL DEFAULT 1,
                    can_vote_again_at TEXT NOT NULL,
                    created_at        TEXT NOT NULL,
                    updated_at        TEXT NOT NULL,
                    UNIQUE(user_id, contestant_id)
                )',
            );
            // For IP-based rate limiting (countRecentByIp)
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_records_ip_time
                 ON vote_records(ip_address, updated_at)',
            );
            // For contestant-level vote history
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_records_contestant
                 ON vote_records(contestant_id)',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_records schema.',
                layer:    'repository.voting.record',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): VoteRecord
    {
        return VoteRecord::reconstitute(
            id:             (string) $row['id'],
            contestantId:   (string) $row['contestant_id'],
            editionId:      (string) $row['edition_id'],
            userId:         (string) $row['user_id'],
            ipAddress:      (string) ($row['ip_address'] ?? ''),
            voteCount:      (int)    $row['vote_count'],
            canVoteAgainAt: (string) $row['can_vote_again_at'],
            createdAt:      (string) $row['created_at'],
            updatedAt:      (string) $row['updated_at'],
        );
    }
}
