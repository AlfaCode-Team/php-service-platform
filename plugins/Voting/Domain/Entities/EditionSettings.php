<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\SubscriptionLevel;

/**
 * All configurable settings and aggregate counters for a single edition.
 * Stored in vote_edition_settings, one row per edition.
 */
final class EditionSettings
{
    private function __construct(
        private readonly EditionId          $editionId,
        // Nomination phase
        private bool                        $nominationEnabled,
        private ?\DateTimeImmutable         $nominationStartDate,
        private ?\DateTimeImmutable         $nominationEndDate,
        private array                       $nominationFields,   // [['name'=>..,'type'=>..,'required'=>..]]
        // Subscription
        private bool                        $subscriptionEnabled,
        private array                       $subscriptionPlans,  // SubscriptionLevel->value => ['daily_votes'=>int,'price'=>int]
        // Boosting
        private bool                        $boostingEnabled,
        private string                      $currency,           // ISO-4217, default UGX
        private array                       $boostTiers,         // [['range'=>int,'price_per_vote'=>int]]
        // Award categories  [{'id','name','description'}]
        private array                       $categories,
        // Display
        private string                      $bannerId,
        private string                      $thumbnailId,
        private array                       $tags,
        // Aggregate counters (maintained by the domain as votes/nominees change)
        private int                         $totalVotes,
        private int                         $totalNominees,
        private readonly \DateTimeImmutable $updatedAt,
    ) {}

    public static function defaults(EditionId $editionId): self
    {
        return new self(
            editionId:           $editionId,
            nominationEnabled:   false,
            nominationStartDate: null,
            nominationEndDate:   null,
            nominationFields:    [],
            subscriptionEnabled: true,
            subscriptionPlans:   self::defaultSubscriptionPlans(),
            boostingEnabled:     true,
            currency:            'UGX',
            boostTiers:          self::defaultBoostTiers(),
            categories:          [],
            bannerId:            '',
            thumbnailId:         '',
            tags:                [],
            totalVotes:          0,
            totalNominees:       0,
            updatedAt:           new \DateTimeImmutable(),
        );
    }

    public static function reconstitute(
        string  $editionId,
        bool    $nominationEnabled,
        ?string $nominationStartDate,
        ?string $nominationEndDate,
        string  $nominationFields,
        bool    $subscriptionEnabled,
        string  $subscriptionPlans,
        bool    $boostingEnabled,
        string  $currency,
        string  $boostTiers,
        string  $bannerId,
        string  $thumbnailId,
        string  $tags,
        string  $categories,
        int     $totalVotes,
        int     $totalNominees,
        string  $updatedAt,
    ): self {
        return new self(
            editionId:           EditionId::from($editionId),
            nominationEnabled:   $nominationEnabled,
            nominationStartDate: $nominationStartDate !== null ? new \DateTimeImmutable($nominationStartDate) : null,
            nominationEndDate:   $nominationEndDate   !== null ? new \DateTimeImmutable($nominationEndDate)   : null,
            nominationFields:    json_decode($nominationFields,   true) ?? [],
            subscriptionEnabled: $subscriptionEnabled,
            subscriptionPlans:   json_decode($subscriptionPlans,  true) ?? self::defaultSubscriptionPlans(),
            boostingEnabled:     $boostingEnabled,
            currency:            $currency,
            boostTiers:          json_decode($boostTiers,         true) ?? self::defaultBoostTiers(),
            categories:          json_decode($categories,         true) ?? [],
            bannerId:            $bannerId,
            thumbnailId:         $thumbnailId,
            tags:                json_decode($tags,               true) ?? [],
            totalVotes:          $totalVotes,
            totalNominees:       $totalNominees,
            updatedAt:           new \DateTimeImmutable($updatedAt),
        );
    }

    // ── Nomination ────────────────────────────────────────────────────────────

    public function updateNomination(
        bool                $enabled,
        ?\DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        array               $fields,
    ): void {
        $this->nominationEnabled   = $enabled;
        $this->nominationStartDate = $startDate;
        $this->nominationEndDate   = $endDate;
        $this->nominationFields    = $fields;
    }

    public function isNominationOpen(): bool
    {
        if (!$this->nominationEnabled) {
            return false;
        }
        $now = new \DateTimeImmutable();
        if ($this->nominationStartDate !== null && $now < $this->nominationStartDate) {
            return false;
        }
        if ($this->nominationEndDate !== null && $now > $this->nominationEndDate) {
            return false;
        }
        return true;
    }

    // ── Subscription ──────────────────────────────────────────────────────────

    public function updateSubscription(bool $enabled, array $plans): void
    {
        $this->subscriptionEnabled = $enabled;
        $this->subscriptionPlans   = $plans;
    }

    public function dailyVotesForLevel(SubscriptionLevel $level): int
    {
        return (int) ($this->subscriptionPlans[$level->value]['daily_votes'] ?? $level->defaultDailyVotes());
    }

    public function priceForLevel(SubscriptionLevel $level): int
    {
        return (int) ($this->subscriptionPlans[$level->value]['price'] ?? $level->defaultPrice());
    }

    // ── Boosting ──────────────────────────────────────────────────────────────

    public function updateBoosting(bool $enabled, string $currency, array $tiers): void
    {
        $this->boostingEnabled = $enabled;
        $this->currency        = $currency;
        $this->boostTiers      = $tiers;
    }

    /** Calculates total cost in currency units for N votes using tiered pricing. */
    public function calculateBoostCost(int $votes): int
    {
        if ($votes < 1) {
            throw new \DomainException('Boost vote count must be at least 1.');
        }

        $total     = 0;
        $remaining = $votes;

        foreach ($this->boostTiers as $tier) {
            if ($remaining <= 0) {
                break;
            }
            $inTier = min($remaining, (int) $tier['range']);
            $total += $inTier * (int) $tier['price_per_vote'];
            $remaining -= $inTier;
        }

        if ($remaining > 0) {
            $lastTier = end($this->boostTiers);
            $total   += $remaining * (int) ($lastTier['price_per_vote'] ?? 100);
        }

        return min($total, 1_000_000);
    }

    // ── Award categories ─────────────────────────────────────────────────────
    // Each entry: ['id' => string, 'name' => string, 'description' => string]

    public function addCategory(string $id, string $name, string $description = ''): void
    {
        $id   = trim($id);
        $name = trim($name);

        if ($id === '') {
            throw new \DomainException('Category id cannot be empty.');
        }
        if ($name === '') {
            throw new \DomainException('Category name cannot be empty.');
        }
        if ($this->findCategory($id) !== null) {
            throw new \DomainException("Category [{$id}] already exists.");
        }

        $this->categories[] = ['id' => $id, 'name' => $name, 'description' => $description];
    }

    public function updateCategory(string $id, string $name, string $description = ''): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \DomainException('Category name cannot be empty.');
        }

        foreach ($this->categories as $i => $cat) {
            if ($cat['id'] === $id) {
                $this->categories[$i] = ['id' => $id, 'name' => $name, 'description' => $description];
                return;
            }
        }

        throw new \DomainException("Category [{$id}] not found.");
    }

    public function removeCategory(string $id): void
    {
        foreach ($this->categories as $i => $cat) {
            if ($cat['id'] === $id) {
                array_splice($this->categories, $i, 1);
                return;
            }
        }

        throw new \DomainException("Category [{$id}] not found.");
    }

    /** @return array{id:string,name:string,description:string}|null */
    public function findCategory(string $id): ?array
    {
        foreach ($this->categories as $cat) {
            if ($cat['id'] === $id) {
                return $cat;
            }
        }
        return null;
    }

    public function replaceCategories(array $categories): void
    {
        foreach ($categories as $cat) {
            if (empty($cat['id']) || empty($cat['name'])) {
                throw new \DomainException('Each category must have an id and a name.');
            }
        }
        $this->categories = array_values($categories);
    }

    // ── Display / meta ────────────────────────────────────────────────────────

    public function updateDisplay(string $bannerId, string $thumbnailId, array $tags): void
    {
        $this->bannerId    = $bannerId;
        $this->thumbnailId = $thumbnailId;
        $this->tags        = $tags;
    }

    // ── Aggregate counters ────────────────────────────────────────────────────

    public function incrementVotes(int $by = 1): void  { $this->totalVotes    += $by; }
    public function incrementNominees(): void           { $this->totalNominees += 1; }

    // ── Serialisation helpers ─────────────────────────────────────────────────

    public function subscriptionPlansJson(): string  { return (string) json_encode($this->subscriptionPlans); }
    public function nominationFieldsJson(): string   { return (string) json_encode($this->nominationFields); }
    public function boostTiersJson(): string         { return (string) json_encode($this->boostTiers); }
    public function categoriesJson(): string         { return (string) json_encode(array_values($this->categories)); }
    public function tagsJson(): string               { return (string) json_encode($this->tags); }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function editionId(): EditionId              { return $this->editionId; }
    public function nominationEnabled(): bool           { return $this->nominationEnabled; }
    public function nominationStartDate(): ?\DateTimeImmutable { return $this->nominationStartDate; }
    public function nominationEndDate(): ?\DateTimeImmutable   { return $this->nominationEndDate; }
    public function nominationFields(): array           { return $this->nominationFields; }
    public function subscriptionEnabled(): bool         { return $this->subscriptionEnabled; }
    public function subscriptionPlans(): array          { return $this->subscriptionPlans; }
    public function boostingEnabled(): bool             { return $this->boostingEnabled; }
    public function currency(): string                  { return $this->currency; }
    public function boostTiers(): array                 { return $this->boostTiers; }
    public function categories(): array                 { return $this->categories; }
    public function bannerId(): string                  { return $this->bannerId; }
    public function thumbnailId(): string               { return $this->thumbnailId; }
    public function tags(): array                       { return $this->tags; }
    public function totalVotes(): int                   { return $this->totalVotes; }
    public function totalNominees(): int                { return $this->totalNominees; }
    public function updatedAt(): \DateTimeImmutable     { return $this->updatedAt; }

    // ── Defaults ──────────────────────────────────────────────────────────────

    /** @return array<string, array{daily_votes: int, price: int}> */
    private static function defaultSubscriptionPlans(): array
    {
        $plans = [];
        foreach (SubscriptionLevel::cases() as $level) {
            $plans[$level->value] = [
                'daily_votes' => $level->defaultDailyVotes(),
                'price'       => $level->defaultPrice(),
            ];
        }
        return $plans;
    }

    /** @return list<array{range: int, price_per_vote: int}> */
    private static function defaultBoostTiers(): array
    {
        return [
            ['range' => 20,    'price_per_vote' => 1000],
            ['range' => 40,    'price_per_vote' => 900],
            ['range' => 80,    'price_per_vote' => 800],
            ['range' => 160,   'price_per_vote' => 700],
            ['range' => 320,   'price_per_vote' => 600],
            ['range' => 640,   'price_per_vote' => 500],
            ['range' => 1280,  'price_per_vote' => 400],
            ['range' => 2560,  'price_per_vote' => 300],
            ['range' => 5120,  'price_per_vote' => 200],
            ['range' => 10000, 'price_per_vote' => 100],
        ];
    }
}
