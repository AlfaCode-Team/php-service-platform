<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use Plugins\Voting\Domain\Entities\UserSubscription;

final readonly class SubscriptionDTO
{
    public function __construct(
        public string  $id,
        public string  $userId,
        public string  $editionId,
        public string  $level,
        public int     $levelKey,
        public int     $remainingVotesToday,
        public string  $updatedAt,
        public ?string $paymentLink,
    ) {}

    public static function fromEntity(UserSubscription $sub, ?string $paymentLink = null): self
    {
        return new self(
            id:                  $sub->id(),
            userId:              $sub->userId(),
            editionId:           $sub->editionId()->value(),
            level:               $sub->level()->value,
            levelKey:            $sub->level()->key(),
            remainingVotesToday: $sub->remainingVotesToday(),
            updatedAt:           $sub->updatedAt()->format(\DateTimeInterface::RFC3339),
            paymentLink:         $paymentLink,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'user_id'              => $this->userId,
            'edition_id'           => $this->editionId,
            'level'                => $this->level,
            'level_key'            => $this->levelKey,
            'remaining_votes_today'=> $this->remainingVotesToday,
            'updated_at'           => $this->updatedAt,
            'payment_link'         => $this->paymentLink,
        ];
    }
}
