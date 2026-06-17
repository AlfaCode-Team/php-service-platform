<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Voting\Domain\Entities\UserSubscription;
use Plugins\Voting\Domain\ValueObjects\EditionId;

final class UserSubscriptionRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
        $this->ensureSchema();
    }

    public function findByUserAndEdition(string $userId, string $editionId): ?UserSubscription
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, user_id, edition_id, level, daily_allowance,
                        transaction_id, created_at, updated_at
                 FROM vote_user_subscriptions
                 WHERE user_id = :user_id AND edition_id = :edition_id',
                ['user_id' => $userId, 'edition_id' => $editionId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load subscription for user [{$userId}] edition [{$editionId}].",
                layer:   'repository.voting.subscription',
                context: ['user_id' => $userId, 'edition_id' => $editionId],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function findByTransactionId(string $txId): ?UserSubscription
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, user_id, edition_id, level, daily_allowance,
                        transaction_id, created_at, updated_at
                 FROM vote_user_subscriptions
                 WHERE transaction_id = :tx_id',
                ['tx_id' => $txId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load subscription by transaction [{$txId}].",
                layer:    'repository.voting.subscription',
                context:  ['transaction_id' => $txId],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function save(UserSubscription $sub): void
    {
        try {
            $this->db->execute(
                'INSERT INTO vote_user_subscriptions
                    (id, user_id, edition_id, level, daily_allowance, transaction_id, created_at, updated_at)
                 VALUES
                    (:id, :user_id, :edition_id, :level, :daily_allowance, :transaction_id, :created_at, :updated_at)
                 ON CONFLICT(user_id, edition_id) DO UPDATE SET
                    level           = :level,
                    daily_allowance = :daily_allowance,
                    transaction_id  = :transaction_id,
                    updated_at      = :updated_at',
                [
                    'id'             => $sub->id(),
                    'user_id'        => $sub->userId(),
                    'edition_id'     => $sub->editionId()->value(),
                    'level'          => $sub->level()->value,
                    'daily_allowance'=> $sub->dailyAllowanceJson(),
                    'transaction_id' => $sub->transactionId(),
                    'created_at'     => $sub->createdAt()->format(\DateTimeInterface::RFC3339),
                    'updated_at'     => $sub->updatedAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save subscription [{$sub->id()}].",
                layer:    'repository.voting.subscription',
                context:  ['id' => $sub->id()],
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
                'CREATE TABLE IF NOT EXISTS vote_user_subscriptions (
                    id              TEXT NOT NULL PRIMARY KEY,
                    user_id         TEXT NOT NULL,
                    edition_id      TEXT NOT NULL,
                    level           TEXT NOT NULL DEFAULT \'free\',
                    daily_allowance TEXT NOT NULL DEFAULT \'{}\',
                    transaction_id  TEXT NOT NULL DEFAULT \'\',
                    created_at      TEXT NOT NULL,
                    updated_at      TEXT NOT NULL,
                    UNIQUE(user_id, edition_id)
                )',
            );
            // For confirm() lookup by transaction id
            $this->db->execute(
                'CREATE INDEX IF NOT EXISTS idx_vote_user_subs_tx
                 ON vote_user_subscriptions(transaction_id)',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_user_subscriptions schema.',
                layer:    'repository.voting.subscription',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): UserSubscription
    {
        return UserSubscription::reconstitute(
            id:             (string) $row['id'],
            userId:         (string) $row['user_id'],
            editionId:      (string) $row['edition_id'],
            level:          (string) $row['level'],
            dailyAllowance: (string) $row['daily_allowance'],
            transactionId:  (string) $row['transaction_id'],
            createdAt:      (string) $row['created_at'],
            updatedAt:      (string) $row['updated_at'],
        );
    }
}
