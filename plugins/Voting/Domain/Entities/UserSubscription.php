<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\Events\SubscriptionUpgradedDomainEvent;
use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\SubscriptionLevel;

final class UserSubscription
{
    /** @var list<SubscriptionUpgradedDomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly string             $id,
        private readonly string             $userId,
        private readonly EditionId          $editionId,
        private SubscriptionLevel           $level,
        private array                       $dailyAllowance, // ['Y-m-d' => remaining_votes]
        private string                      $transactionId,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable          $updatedAt,
    ) {}

    public static function free(string $userId, EditionId $editionId): self
    {
        $now = new \DateTimeImmutable();
        return new self(
            id:             bin2hex(random_bytes(8)),
            userId:         $userId,
            editionId:      $editionId,
            level:          SubscriptionLevel::Free,
            dailyAllowance: [],
            transactionId:  '',
            createdAt:      $now,
            updatedAt:      $now,
        );
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $editionId,
        string $level,
        string $dailyAllowance,
        string $transactionId,
        string $createdAt,
        string $updatedAt,
    ): self {
        return new self(
            id:             $id,
            userId:         $userId,
            editionId:      EditionId::from($editionId),
            level:          SubscriptionLevel::from($level),
            dailyAllowance: json_decode($dailyAllowance, true) ?? [],
            transactionId:  $transactionId,
            createdAt:      new \DateTimeImmutable($createdAt),
            updatedAt:      new \DateTimeImmutable($updatedAt),
        );
    }

    public function upgrade(SubscriptionLevel $newLevel, int $dailyVotes, string $txId): void
    {
        if (!$newLevel->isHigherThan($this->level)) {
            throw new \DomainException(
                "Cannot downgrade subscription from {$this->level->value} to {$newLevel->value}."
            );
        }

        $previous      = $this->level;
        $this->level   = $newLevel;
        $this->transactionId = $txId;
        $this->updatedAt     = new \DateTimeImmutable();

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $this->dailyAllowance[$today] = $dailyVotes;

        $this->domainEvents[] = new SubscriptionUpgradedDomainEvent(
            userId:      $this->userId,
            editionId:   $this->editionId,
            fromLevel:   $previous,
            toLevel:     $newLevel,
            occurredAt:  $this->updatedAt,
        );
    }

    public function remainingVotesToday(): int
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        return (int) ($this->dailyAllowance[$today] ?? 0);
    }

    public function deductVoteToday(): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $current = (int) ($this->dailyAllowance[$today] ?? 0);

        if ($current <= 0) {
            throw new \DomainException('No subscription votes remaining today.');
        }

        $this->dailyAllowance[$today] = $current - 1;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function refreshDailyAllowance(int $dailyVotes): void
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        if (!isset($this->dailyAllowance[$today])) {
            $this->dailyAllowance[$today] = $dailyVotes;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function dailyAllowanceJson(): string { return (string) json_encode($this->dailyAllowance); }

    /** @return list<SubscriptionUpgradedDomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function id(): string                          { return $this->id; }
    public function userId(): string                      { return $this->userId; }
    public function editionId(): EditionId                { return $this->editionId; }
    public function level(): SubscriptionLevel            { return $this->level; }
    public function transactionId(): string               { return $this->transactionId; }
    public function createdAt(): \DateTimeImmutable       { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable       { return $this->updatedAt; }
}
