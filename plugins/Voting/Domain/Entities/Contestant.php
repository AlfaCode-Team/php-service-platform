<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\Events\ContestantAddedDomainEvent;
use Plugins\Voting\Domain\ValueObjects\ContestantId;
use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\VoteCount;

final class Contestant
{
    /** @var list<ContestantAddedDomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly ContestantId       $id,
        private readonly EditionId          $editionId,
        private readonly string             $organiserId,
        private string                      $fullName,
        private string                      $slug,
        private string                      $avatarId,
        private string                      $detail,
        private string                      $categoryId,
        private VoteCount                   $voteCount,
        private int                         $boostCount,
        private readonly \DateTimeImmutable $registeredAt,
    ) {}

    public static function add(
        EditionId $editionId,
        string    $organiserId,
        string    $fullName,
        string    $slug,
        string    $avatarId   = '',
        string    $detail     = '',
        string    $categoryId = '',
    ): self {
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw new \DomainException('Contestant full name cannot be empty.');
        }

        $slug = trim($slug);
        if ($slug === '') {
            throw new \DomainException('Contestant slug cannot be empty.');
        }

        $contestant = new self(
            id:           ContestantId::generate(),
            editionId:    $editionId,
            organiserId:  $organiserId,
            fullName:     $fullName,
            slug:         $slug,
            avatarId:     $avatarId,
            detail:       $detail,
            categoryId:   $categoryId,
            voteCount:    VoteCount::zero(),
            boostCount:   0,
            registeredAt: new \DateTimeImmutable(),
        );

        $contestant->domainEvents[] = new ContestantAddedDomainEvent(
            contestantId: $contestant->id,
            editionId:    $editionId,
            fullName:     $fullName,
            occurredAt:   $contestant->registeredAt,
        );

        return $contestant;
    }

    public static function reconstitute(
        string $id,
        string $editionId,
        string $organiserId,
        string $fullName,
        string $slug,
        string $avatarId,
        string $detail,
        string $categoryId,
        int    $voteCount,
        int    $boostCount,
        string $registeredAt,
    ): self {
        return new self(
            id:           ContestantId::from($id),
            editionId:    EditionId::from($editionId),
            organiserId:  $organiserId,
            fullName:     $fullName,
            slug:         $slug,
            avatarId:     $avatarId,
            detail:       $detail,
            categoryId:   $categoryId,
            voteCount:    VoteCount::of($voteCount),
            boostCount:   $boostCount,
            registeredAt: new \DateTimeImmutable($registeredAt),
        );
    }

    public function incrementVote(): void
    {
        $this->voteCount = $this->voteCount->increment();
    }

    public function incrementVotes(int $by): void
    {
        $this->voteCount = $this->voteCount->incrementBy($by);
    }

    public function incrementBoost(int $by): void
    {
        if ($by < 1) {
            throw new \DomainException('Boost increment must be at least 1.');
        }
        $this->boostCount += $by;
    }

    /** @return list<ContestantAddedDomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function id(): ContestantId                 { return $this->id; }
    public function editionId(): EditionId             { return $this->editionId; }
    public function organiserId(): string              { return $this->organiserId; }
    public function fullName(): string                 { return $this->fullName; }
    public function slug(): string                     { return $this->slug; }
    public function avatarId(): string                 { return $this->avatarId; }
    public function detail(): string                   { return $this->detail; }
    public function categoryId(): string               { return $this->categoryId; }
    public function voteCount(): VoteCount             { return $this->voteCount; }
    public function boostCount(): int                  { return $this->boostCount; }
    public function registeredAt(): \DateTimeImmutable { return $this->registeredAt; }
}
