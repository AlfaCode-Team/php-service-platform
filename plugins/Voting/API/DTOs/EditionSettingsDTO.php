<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use Plugins\Voting\Domain\Entities\EditionSettings;

final readonly class EditionSettingsDTO
{
    public function __construct(
        public string  $editionId,
        public bool    $nominationEnabled,
        public ?string $nominationStartDate,
        public ?string $nominationEndDate,
        public array   $nominationFields,
        public bool    $subscriptionEnabled,
        public array   $subscriptionPlans,
        public bool    $boostingEnabled,
        public string  $currency,
        public array   $boostTiers,
        public array   $categories,
        public string  $bannerId,
        public string  $thumbnailId,
        public array   $tags,
        public int     $totalVotes,
        public int     $totalNominees,
    ) {}

    public static function fromEntity(EditionSettings $s): self
    {
        return new self(
            editionId:           $s->editionId()->value(),
            nominationEnabled:   $s->nominationEnabled(),
            nominationStartDate: $s->nominationStartDate()?->format(\DateTimeInterface::RFC3339),
            nominationEndDate:   $s->nominationEndDate()?->format(\DateTimeInterface::RFC3339),
            nominationFields:    $s->nominationFields(),
            subscriptionEnabled: $s->subscriptionEnabled(),
            subscriptionPlans:   $s->subscriptionPlans(),
            boostingEnabled:     $s->boostingEnabled(),
            currency:            $s->currency(),
            boostTiers:          $s->boostTiers(),
            categories:          $s->categories(),
            bannerId:            $s->bannerId(),
            thumbnailId:         $s->thumbnailId(),
            tags:                $s->tags(),
            totalVotes:          $s->totalVotes(),
            totalNominees:       $s->totalNominees(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'edition_id'           => $this->editionId,
            'nomination_enabled'   => $this->nominationEnabled,
            'nomination_start_date'=> $this->nominationStartDate,
            'nomination_end_date'  => $this->nominationEndDate,
            'nomination_fields'    => $this->nominationFields,
            'subscription_enabled' => $this->subscriptionEnabled,
            'subscription_plans'   => $this->subscriptionPlans,
            'boosting_enabled'     => $this->boostingEnabled,
            'currency'             => $this->currency,
            'boost_tiers'          => $this->boostTiers,
            'categories'           => $this->categories,
            'banner_id'            => $this->bannerId,
            'thumbnail_id'         => $this->thumbnailId,
            'tags'                 => $this->tags,
            'total_votes'          => $this->totalVotes,
            'total_nominees'       => $this->totalNominees,
        ];
    }
}
