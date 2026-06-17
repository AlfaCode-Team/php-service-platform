<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Voting\Domain\Entities\EditionSettings;
use Plugins\Voting\Domain\ValueObjects\EditionId;

final class EditionSettingsRepository
{
    private static bool $schemaInitialized = false;

    public function __construct(
        private readonly DatabasePort $db,
    ) {
        $this->ensureSchema();
    }

    public function findOrCreate(EditionId $editionId): EditionSettings
    {
        $settings = $this->find($editionId->value());
        if ($settings !== null) {
            return $settings;
        }

        $defaults = EditionSettings::defaults($editionId);
        $this->save($defaults);
        return $defaults;
    }

    public function atomicIncrementVoteCount(string $editionId, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }
        try {
            $this->db->execute(
                'UPDATE vote_edition_settings SET total_votes = total_votes + :by
                 WHERE edition_id = :id',
                ['by' => $by, 'id' => $editionId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to increment vote count for edition [{$editionId}].",
                layer:    'repository.voting.edition_settings',
                context:  ['edition_id' => $editionId],
                previous: $e,
            );
        }
    }

    public function atomicIncrementNominees(string $editionId): void
    {
        try {
            $this->db->execute(
                'UPDATE vote_edition_settings SET total_nominees = total_nominees + 1
                 WHERE edition_id = :id',
                ['id' => $editionId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to increment nominee count for edition [{$editionId}].",
                layer:    'repository.voting.edition_settings',
                context:  ['edition_id' => $editionId],
                previous: $e,
            );
        }
    }

    public function find(string $editionId): ?EditionSettings
    {
        try {
            $row = $this->db->queryOne(
                'SELECT edition_id, nomination_enabled, nomination_start, nomination_end,
                        nomination_fields, subscription_enabled, subscription_plans,
                        boosting_enabled, currency, boost_tiers, categories,
                        banner_id, thumbnail_id, tags, total_votes, total_nominees, updated_at
                 FROM vote_edition_settings
                 WHERE edition_id = :id',
                ['id' => $editionId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load edition settings [{$editionId}].",
                layer:    'repository.voting.edition_settings',
                context:  ['edition_id' => $editionId],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function save(EditionSettings $settings): void
    {
        try {
            $now = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);

            $this->db->execute(
                'INSERT INTO vote_edition_settings
                    (edition_id, nomination_enabled, nomination_start, nomination_end,
                     nomination_fields, subscription_enabled, subscription_plans,
                     boosting_enabled, currency, boost_tiers, categories,
                     banner_id, thumbnail_id, tags, total_votes, total_nominees, updated_at)
                 VALUES
                    (:edition_id, :nomination_enabled, :nomination_start, :nomination_end,
                     :nomination_fields, :subscription_enabled, :subscription_plans,
                     :boosting_enabled, :currency, :boost_tiers, :categories,
                     :banner_id, :thumbnail_id, :tags, :total_votes, :total_nominees, :updated_at)
                 ON CONFLICT(edition_id) DO UPDATE SET
                    nomination_enabled   = :nomination_enabled,
                    nomination_start     = :nomination_start,
                    nomination_end       = :nomination_end,
                    nomination_fields    = :nomination_fields,
                    subscription_enabled = :subscription_enabled,
                    subscription_plans   = :subscription_plans,
                    boosting_enabled     = :boosting_enabled,
                    currency             = :currency,
                    boost_tiers          = :boost_tiers,
                    categories           = :categories,
                    banner_id            = :banner_id,
                    thumbnail_id         = :thumbnail_id,
                    tags                 = :tags,
                    total_votes          = :total_votes,
                    total_nominees       = :total_nominees,
                    updated_at           = :updated_at',
                [
                    'edition_id'           => $settings->editionId()->value(),
                    'nomination_enabled'   => (int) $settings->nominationEnabled(),
                    'nomination_start'     => $settings->nominationStartDate()?->format(\DateTimeInterface::RFC3339),
                    'nomination_end'       => $settings->nominationEndDate()?->format(\DateTimeInterface::RFC3339),
                    'nomination_fields'    => $settings->nominationFieldsJson(),
                    'subscription_enabled' => (int) $settings->subscriptionEnabled(),
                    'subscription_plans'   => $settings->subscriptionPlansJson(),
                    'boosting_enabled'     => (int) $settings->boostingEnabled(),
                    'currency'             => $settings->currency(),
                    'boost_tiers'          => $settings->boostTiersJson(),
                    'categories'           => $settings->categoriesJson(),
                    'banner_id'            => $settings->bannerId(),
                    'thumbnail_id'         => $settings->thumbnailId(),
                    'tags'                 => $settings->tagsJson(),
                    'total_votes'          => $settings->totalVotes(),
                    'total_nominees'       => $settings->totalNominees(),
                    'updated_at'           => $now,
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save edition settings [{$settings->editionId()->value()}].",
                layer:    'repository.voting.edition_settings',
                context:  ['edition_id' => $settings->editionId()->value()],
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
                'CREATE TABLE IF NOT EXISTS vote_edition_settings (
                    edition_id           TEXT NOT NULL PRIMARY KEY,
                    nomination_enabled   INTEGER NOT NULL DEFAULT 0,
                    nomination_start     TEXT,
                    nomination_end       TEXT,
                    nomination_fields    TEXT NOT NULL DEFAULT \'[]\',
                    subscription_enabled INTEGER NOT NULL DEFAULT 1,
                    subscription_plans   TEXT NOT NULL DEFAULT \'{}\',
                    boosting_enabled     INTEGER NOT NULL DEFAULT 1,
                    currency             TEXT NOT NULL DEFAULT \'UGX\',
                    boost_tiers          TEXT NOT NULL DEFAULT \'[]\',
                    categories           TEXT NOT NULL DEFAULT \'[]\',
                    banner_id            TEXT NOT NULL DEFAULT \'\',
                    thumbnail_id         TEXT NOT NULL DEFAULT \'\',
                    tags                 TEXT NOT NULL DEFAULT \'[]\',
                    total_votes          INTEGER NOT NULL DEFAULT 0,
                    total_nominees       INTEGER NOT NULL DEFAULT 0,
                    updated_at           TEXT NOT NULL
                )',
            );
            self::$schemaInitialized = true;
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise vote_edition_settings schema.',
                layer:    'repository.voting.edition_settings',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): EditionSettings
    {
        return EditionSettings::reconstitute(
            editionId:           (string) $row['edition_id'],
            nominationEnabled:   (bool)   $row['nomination_enabled'],
            nominationStartDate: isset($row['nomination_start']) ? (string) $row['nomination_start'] : null,
            nominationEndDate:   isset($row['nomination_end'])   ? (string) $row['nomination_end']   : null,
            nominationFields:    (string) $row['nomination_fields'],
            subscriptionEnabled: (bool)   $row['subscription_enabled'],
            subscriptionPlans:   (string) $row['subscription_plans'],
            boostingEnabled:     (bool)   $row['boosting_enabled'],
            currency:            (string) $row['currency'],
            boostTiers:          (string) $row['boost_tiers'],
            bannerId:            (string) $row['banner_id'],
            thumbnailId:         (string) $row['thumbnail_id'],
            tags:                (string) $row['tags'],
            categories:          (string) ($row['categories'] ?? '[]'),
            totalVotes:          (int)    $row['total_votes'],
            totalNominees:       (int)    $row['total_nominees'],
            updatedAt:           (string) $row['updated_at'],
        );
    }
}
