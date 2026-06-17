<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Voting\Domain\Entities\Transaction;

final class TransactionRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
        $this->ensureSchema();
    }

    public function find(string $txRef): ?Transaction
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, user_id, amount, currency, status, type, metadata, created_at, updated_at
                 FROM vote_transactions
                 WHERE id = :id',
                ['id' => $txRef],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load transaction [{$txRef}].",
                layer:    'repository.voting.transaction',
                context:  ['id' => $txRef],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function save(Transaction $tx): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_transactions
                    (id, user_id, amount, currency, status, type, metadata, created_at, updated_at)
                 VALUES
                    (:id, :user_id, :amount, :currency, :status, :type, :metadata, :created_at, :updated_at)
                 ON CONFLICT(id) DO UPDATE SET
                    status     = :status,
                    updated_at = :updated_at',
                [
                    'id'         => $tx->id(),
                    'user_id'    => $tx->userId(),
                    'amount'     => $tx->amount(),
                    'currency'   => $tx->currency(),
                    'status'     => $tx->status()->value,
                    'type'       => $tx->type()->value,
                    'metadata'   => $tx->metadataJson(),
                    'created_at' => $tx->createdAt()->format(\DateTimeInterface::RFC3339),
                    'updated_at' => $tx->updatedAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save transaction [{$tx->id()}].",
                layer:    'repository.voting.transaction',
                context:  ['id' => $tx->id()],
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
                'CREATE TABLE IF NOT EXISTS vote_transactions (
                    id         TEXT NOT NULL PRIMARY KEY,
                    user_id    TEXT NOT NULL,
                    amount     INTEGER NOT NULL DEFAULT 0,
                    currency   TEXT NOT NULL DEFAULT \'UGX\',
                    status     TEXT NOT NULL DEFAULT \'pending\',
                    type       TEXT NOT NULL,
                    metadata   TEXT NOT NULL DEFAULT \'{}\',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )',
            );
            // For user transaction history
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_transactions_user_status
                 ON vote_transactions(user_id, status)',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_transactions schema.',
                layer:    'repository.voting.transaction',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Transaction
    {
        return Transaction::reconstitute(
            id:        (string) $row['id'],
            userId:    (string) $row['user_id'],
            amount:    (int)    $row['amount'],
            currency:  (string) $row['currency'],
            status:    (string) $row['status'],
            type:      (string) $row['type'],
            metadata:  (string) $row['metadata'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
