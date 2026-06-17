<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use Plugins\Voting\Domain\Entities\Contestant;

final readonly class ContestantDTO
{
    public function __construct(
        public string $id,
        public string $editionId,
        public string $organiserId,
        public string $fullName,
        public string $slug,
        public string $avatarId,
        public string $detail,
        public string $categoryId,
        public int    $voteCount,
        public int    $boostCount,
        public string $registeredAt,
    ) {}

    public static function fromEntity(Contestant $contestant): self
    {
        return new self(
            id:           $contestant->id()->value(),
            editionId:    $contestant->editionId()->value(),
            organiserId:  $contestant->organiserId(),
            fullName:     $contestant->fullName(),
            slug:         $contestant->slug(),
            avatarId:     $contestant->avatarId(),
            detail:       $contestant->detail(),
            categoryId:   $contestant->categoryId(),
            voteCount:    $contestant->voteCount()->value(),
            boostCount:   $contestant->boostCount(),
            registeredAt: $contestant->registeredAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'edition_id'    => $this->editionId,
            'organiser_id'  => $this->organiserId,
            'full_name'     => $this->fullName,
            'slug'          => $this->slug,
            'avatar_id'     => $this->avatarId,
            'detail'        => $this->detail,
            'category_id'   => $this->categoryId,
            'vote_count'    => $this->voteCount,
            'boost_count'   => $this->boostCount,
            'registered_at' => $this->registeredAt,
        ];
    }
}
