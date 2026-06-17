<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Entities;

use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\EditionStatus;

final class Edition
{
    /** @var list<object> */
    private array $domainEvents = [];

    private function __construct(
        private readonly EditionId          $id,
        private string                      $title,
        private readonly string             $slug,
        private readonly string             $organiserId,
        private EditionStatus               $status,
        private ?\DateTimeImmutable         $startDate,
        private ?\DateTimeImmutable         $endDate,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function create(
        string              $title,
        string              $slug,
        string              $organiserId,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate   = null,
    ): self {
        $title = trim($title);
        if ($title === '') {
            throw new \DomainException('Edition title cannot be empty.');
        }

        $slug = trim($slug);
        if ($slug === '') {
            throw new \DomainException('Edition slug cannot be empty.');
        }

        return new self(
            id:          EditionId::generate(),
            title:       $title,
            slug:        $slug,
            organiserId: $organiserId,
            status:      EditionStatus::Draft,
            startDate:   $startDate,
            endDate:     $endDate,
            createdAt:   new \DateTimeImmutable(),
        );
    }

    public static function reconstitute(
        string  $id,
        string  $title,
        string  $slug,
        string  $organiserId,
        string  $status,
        ?string $startDate,
        ?string $endDate,
        string  $createdAt,
    ): self {
        return new self(
            id:          EditionId::from($id),
            title:       $title,
            slug:        $slug,
            organiserId: $organiserId,
            status:      EditionStatus::from($status),
            startDate:   $startDate !== null ? new \DateTimeImmutable($startDate) : null,
            endDate:     $endDate   !== null ? new \DateTimeImmutable($endDate)   : null,
            createdAt:   new \DateTimeImmutable($createdAt),
        );
    }

    public function activate(): void
    {
        if (!$this->status->isDraft()) {
            throw new \DomainException('Only a draft edition can be activated.');
        }
        $this->status = EditionStatus::Active;
    }

    public function close(): void
    {
        if ($this->status->isClosed()) {
            throw new \DomainException('Edition is already closed.');
        }
        $this->status = EditionStatus::Closed;
    }

    public function update(string $title, ?\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): void
    {
        $title = trim($title);
        if ($title !== '') {
            $this->title = $title;
        }
        if ($startDate !== null) {
            $this->startDate = $startDate;
        }
        if ($endDate !== null) {
            $this->endDate = $endDate;
        }
    }

    /** @return list<object> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function id(): EditionId                  { return $this->id; }
    public function title(): string                  { return $this->title; }
    public function slug(): string                   { return $this->slug; }
    public function organiserId(): string            { return $this->organiserId; }
    public function status(): EditionStatus          { return $this->status; }
    public function startDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function endDate(): ?\DateTimeImmutable   { return $this->endDate; }
    public function createdAt(): \DateTimeImmutable  { return $this->createdAt; }
}
