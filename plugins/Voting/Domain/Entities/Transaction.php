<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\ValueObjects\TransactionStatus;
use Plugins\Voting\Domain\ValueObjects\TransactionType;

final class Transaction
{
    private function __construct(
        private readonly string            $id,          // Flutterwave tx_ref
        private readonly string            $userId,
        private readonly int               $amount,      // in currency units
        private readonly string            $currency,
        private TransactionStatus          $status,
        private readonly TransactionType   $type,
        private readonly array             $metadata,    // arbitrary JSON payload
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable          $updatedAt,
    ) {}

    public static function create(
        string          $txRef,
        string          $userId,
        int             $amount,
        string          $currency,
        TransactionType $type,
        array           $metadata,
    ): self {
        if ($amount < 0) {
            throw new \DomainException('Transaction amount cannot be negative.');
        }

        $now = new \DateTimeImmutable();
        return new self(
            id:        $txRef,
            userId:    $userId,
            amount:    $amount,
            currency:  $currency,
            status:    TransactionStatus::Pending,
            type:      $type,
            metadata:  $metadata,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public static function reconstitute(
        string $id,
        string $userId,
        int    $amount,
        string $currency,
        string $status,
        string $type,
        string $metadata,
        string $createdAt,
        string $updatedAt,
    ): self {
        return new self(
            id:        $id,
            userId:    $userId,
            amount:    $amount,
            currency:  $currency,
            status:    TransactionStatus::from($status),
            type:      TransactionType::from($type),
            metadata:  json_decode($metadata, true) ?? [],
            createdAt: new \DateTimeImmutable($createdAt),
            updatedAt: new \DateTimeImmutable($updatedAt),
        );
    }

    public function complete(): void
    {
        if (!$this->status->isPending()) {
            throw new \DomainException('Only a pending transaction can be completed.');
        }
        $this->status    = TransactionStatus::Completed;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function fail(): void
    {
        $this->status    = TransactionStatus::Failed;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function metadataJson(): string { return (string) json_encode($this->metadata); }

    public function id(): string                         { return $this->id; }
    public function userId(): string                     { return $this->userId; }
    public function amount(): int                        { return $this->amount; }
    public function currency(): string                   { return $this->currency; }
    public function status(): TransactionStatus          { return $this->status; }
    public function type(): TransactionType              { return $this->type; }
    public function metadata(): array                    { return $this->metadata; }
    public function createdAt(): \DateTimeImmutable      { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable      { return $this->updatedAt; }
}
