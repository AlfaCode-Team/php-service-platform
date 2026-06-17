<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\Events\VoteCastDomainEvent;
use Plugins\Voting\Domain\ValueObjects\ContestantId;
use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\VoteCount;

final class VoteRecord
{
    /** @var list<VoteCastDomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly string             $id,
        private readonly ContestantId       $contestantId,
        private readonly EditionId          $editionId,
        private readonly string             $userId,
        private string                      $ipAddress,
        private VoteCount                   $voteCount,
        private \DateTimeImmutable          $canVoteAgainAt,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable          $updatedAt,
    ) {}

    public static function cast(
        ContestantId $contestantId,
        EditionId    $editionId,
        string       $userId,
        string       $ipAddress,
        int          $cooldownHours,
    ): self {
        $now = new \DateTimeImmutable();

        $record = new self(
            id:             bin2hex(random_bytes(8)),
            contestantId:   $contestantId,
            editionId:      $editionId,
            userId:         $userId,
            ipAddress:      $ipAddress,
            voteCount:      VoteCount::of(1),
            canVoteAgainAt: $now->modify("+{$cooldownHours} hours"),
            createdAt:      $now,
            updatedAt:      $now,
        );

        $record->domainEvents[] = new VoteCastDomainEvent(
            contestantId: $contestantId,
            editionId:    $editionId,
            userId:       $userId,
            occurredAt:   $now,
        );

        return $record;
    }

    public static function reconstitute(
        string $id,
        string $contestantId,
        string $editionId,
        string $userId,
        string $ipAddress,
        int    $voteCount,
        string $canVoteAgainAt,
        string $createdAt,
        string $updatedAt,
    ): self {
        return new self(
            id:             $id,
            contestantId:   ContestantId::from($contestantId),
            editionId:      EditionId::from($editionId),
            userId:         $userId,
            ipAddress:      $ipAddress,
            voteCount:      VoteCount::of($voteCount),
            canVoteAgainAt: new \DateTimeImmutable($canVoteAgainAt),
            createdAt:      new \DateTimeImmutable($createdAt),
            updatedAt:      new \DateTimeImmutable($updatedAt),
        );
    }

    public function recast(string $ipAddress, int $cooldownHours): void
    {
        $now = new \DateTimeImmutable();
        $this->ipAddress      = $ipAddress;
        $this->voteCount      = $this->voteCount->increment();
        $this->canVoteAgainAt = $now->modify("+{$cooldownHours} hours");
        $this->updatedAt      = $now;

        $this->domainEvents[] = new VoteCastDomainEvent(
            contestantId: $this->contestantId,
            editionId:    $this->editionId,
            userId:       $this->userId,
            occurredAt:   $now,
        );
    }

    public function canVoteNow(): bool
    {
        return new \DateTimeImmutable() >= $this->canVoteAgainAt;
    }

    /** @return list<VoteCastDomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function id(): string                         { return $this->id; }
    public function contestantId(): ContestantId         { return $this->contestantId; }
    public function editionId(): EditionId               { return $this->editionId; }
    public function userId(): string                     { return $this->userId; }
    public function ipAddress(): string                  { return $this->ipAddress; }
    public function voteCount(): VoteCount               { return $this->voteCount; }
    public function canVoteAgainAt(): \DateTimeImmutable { return $this->canVoteAgainAt; }
    public function createdAt(): \DateTimeImmutable      { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable      { return $this->updatedAt; }
}
