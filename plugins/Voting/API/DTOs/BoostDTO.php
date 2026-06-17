<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use Plugins\Voting\Domain\Entities\Boost;

final readonly class BoostDTO
{
    public function __construct(
        public string  $id,
        public string  $userId,
        public string  $contestantId,
        public string  $editionId,
        public int     $boostAmount,
        public int     $boostedVotes,
        public string  $boostType,
        public string  $transactionId,
        public string  $status,
        public string  $boostedAt,
        public ?string $paymentLink,   // present on initiation, null after confirmation
    ) {}

    public static function fromEntity(Boost $boost, ?string $paymentLink = null): self
    {
        return new self(
            id:            $boost->id(),
            userId:        $boost->userId(),
            contestantId:  $boost->contestantId()->value(),
            editionId:     $boost->editionId()->value(),
            boostAmount:   $boost->boostAmount(),
            boostedVotes:  $boost->boostedVotes(),
            boostType:     $boost->boostType()->value,
            transactionId: $boost->transactionId(),
            status:        $boost->status()->value,
            boostedAt:     $boost->boostedAt()->format(\DateTimeInterface::RFC3339),
            paymentLink:   $paymentLink,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'user_id'        => $this->userId,
            'contestant_id'  => $this->contestantId,
            'edition_id'     => $this->editionId,
            'boost_amount'   => $this->boostAmount,
            'boosted_votes'  => $this->boostedVotes,
            'boost_type'     => $this->boostType,
            'transaction_id' => $this->transactionId,
            'status'         => $this->status,
            'boosted_at'     => $this->boostedAt,
            'payment_link'   => $this->paymentLink,
        ];
    }
}
