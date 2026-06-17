<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Voting\Domain\Entities\Boost;

final class BoostRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity     $identity,
    ) {
        $this->ensureSchema();
    }

    public function findByTransactionId(string $txId): ?Boost
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, user_id, contestant_id, edition_id, boost_amount, boosted_votes,
                        boost_type, transaction_id, status, boosted_at
                 FROM vote_boosts
                 WHERE transaction_id = :tx_id AND tenant_id = :tenant',
                ['tx_id' => $txId, 'tenant' => $this->identity->tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load boost by transaction [{$txId}].",
                layer:    'repository.voting.boost',
                context:  ['transaction_id' => $txId],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function save(Boost $boost): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_boosts
                    (id, tenant_id, user_id, contestant_id, edition_id, boost_amount,
                     boosted_votes, boost_type, transaction_id, status, boosted_at)
                 VALUES
                    (:id, :tenant, :user_id, :contestant_id, :edition_id, :boost_amount,
                     :boosted_votes, :boost_type, :transaction_id, :status, :boosted_at)
                 ON CONFLICT(id) DO UPDATE SET
                    status         = :status,
                    transaction_id = :transaction_id',
                [
                    'id'             => $boost->id(),
                    'tenant'         => $this->identity->tenantId,
                    'user_id'        => $boost->userId(),
                    'contestant_id'  => $boost->contestantId()->value(),
                    'edition_id'     => $boost->editionId()->value(),
                    'boost_amount'   => $boost->boostAmount(),
                    'boosted_votes'  => $boost->boostedVotes(),
                    'boost_type'     => $boost->boostType()->value,
                    'transaction_id' => $boost->transactionId(),
                    'status'         => $boost->status()->value,
                    'boosted_at'     => $boost->boostedAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save boost [{$boost->id()}].",
                layer:    'repository.voting.boost',
                context:  ['id' => $boost->id()],
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
                'CREATE TABLE IF NOT EXISTS vote_boosts (
                    id             TEXT NOT NULL PRIMARY KEY,
                    tenant_id      TEXT NOT NULL DEFAULT \'\',
                    user_id        TEXT NOT NULL,
                    contestant_id  TEXT NOT NULL,
                    edition_id     TEXT NOT NULL,
                    boost_amount   INTEGER NOT NULL DEFAULT 0,
                    boosted_votes  INTEGER NOT NULL DEFAULT 0,
                    boost_type     TEXT NOT NULL DEFAULT \'regular\',
                    transaction_id TEXT NOT NULL DEFAULT \'\',
                    status         TEXT NOT NULL DEFAULT \'pending\',
                    boosted_at     TEXT NOT NULL
                )',
            );
            // For confirm() lookup by transaction id
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_boosts_tx
                 ON vote_boosts(transaction_id)',
            );
            // For user boost history
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_boosts_user
                 ON vote_boosts(tenant_id, user_id)',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_boosts schema.',
                layer:    'repository.voting.boost',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Boost
    {
        return Boost::reconstitute(
            id:            (string) $row['id'],
            userId:        (string) $row['user_id'],
            contestantId:  (string) $row['contestant_id'],
            editionId:     (string) $row['edition_id'],
            boostAmount:   (int)    $row['boost_amount'],
            boostedVotes:  (int)    $row['boosted_votes'],
            boostType:     (string) $row['boost_type'],
            transactionId: (string) $row['transaction_id'],
            status:        (string) $row['status'],
            boostedAt:     (string) $row['boosted_at'],
        );
    }
}
