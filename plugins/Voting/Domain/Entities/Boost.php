<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\Events\BoostConfirmedDomainEvent;
use Plugins\Voting\Domain\ValueObjects\BoostStatus;
use Plugins\Voting\Domain\ValueObjects\BoostType;
use Plugins\Voting\Domain\ValueObjects\ContestantId;
use Plugins\Voting\Domain\ValueObjects\EditionId;

final class Boost
{
    /** @var list<BoostConfirmedDomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly string             $id,
        private readonly string             $userId,
        private readonly ContestantId       $contestantId,
        private readonly EditionId          $editionId,
        private readonly int                $boostAmount,
        private readonly int                $boostedVotes,
        private readonly BoostType          $boostType,
        private string                      $transactionId,
        private BoostStatus                 $status,
        private readonly \DateTimeImmutable $boostedAt,
    ) {}

    public static function initiate(
        string       $userId,
        ContestantId $contestantId,
        EditionId    $editionId,
        int          $boostAmount,
        int          $boostedVotes,
        BoostType    $boostType,
        string       $transactionId,
    ): self {
        if ($boostedVotes < 1) {
            throw new \DomainException('Boost must give at least 1 vote.');
        }
        if ($boostAmount < 0) {
            throw new \DomainException('Boost amount cannot be negative.');
        }

        return new self(
            id:            bin2hex(random_bytes(8)),
            userId:        $userId,
            contestantId:  $contestantId,
            editionId:     $editionId,
            boostAmount:   $boostAmount,
            boostedVotes:  $boostedVotes,
            boostType:     $boostType,
            transactionId: $transactionId,
            status:        BoostStatus::Pending,
            boostedAt:     new \DateTimeImmutable(),
        );
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $contestantId,
        string $editionId,
        int    $boostAmount,
        int    $boostedVotes,
        string $boostType,
        string $transactionId,
        string $status,
        string $boostedAt,
    ): self {
        return new self(
            id:            $id,
            userId:        $userId,
            contestantId:  ContestantId::from($contestantId),
            editionId:     EditionId::from($editionId),
            boostAmount:   $boostAmount,
            boostedVotes:  $boostedVotes,
            boostType:     BoostType::from($boostType),
            transactionId: $transactionId,
            status:        BoostStatus::from($status),
            boostedAt:     new \DateTimeImmutable($boostedAt),
        );
    }

    public function confirm(): void
    {
        if ($this->status->isConfirmed()) {
            throw new \DomainException('Boost is already confirmed.');
        }

        $this->status = BoostStatus::Confirmed;

        $this->domainEvents[] = new BoostConfirmedDomainEvent(
            boostId:      $this->id,
            userId:       $this->userId,
            contestantId: $this->contestantId,
            editionId:    $this->editionId,
            boostedVotes: $this->boostedVotes,
            occurredAt:   new \DateTimeImmutable(),
        );
    }

    public function isPending(): bool   { return $this->status->isPending(); }
    public function isConfirmed(): bool { return $this->status->isConfirmed(); }

    /** @return list<BoostConfirmedDomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function id(): string                    { return $this->id; }
    public function userId(): string                { return $this->userId; }
    public function contestantId(): ContestantId    { return $this->contestantId; }
    public function editionId(): EditionId          { return $this->editionId; }
    public function boostAmount(): int              { return $this->boostAmount; }
    public function boostedVotes(): int             { return $this->boostedVotes; }
    public function boostType(): BoostType          { return $this->boostType; }
    public function transactionId(): string         { return $this->transactionId; }
    public function status(): BoostStatus           { return $this->status; }
    public function boostedAt(): \DateTimeImmutable { return $this->boostedAt; }
}
