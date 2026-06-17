<?php

declare(strict_types=1);

namespace Plugins\Invoice\Domain\Entities;

final class Invoice
{
    /** @var array<int,object> */
    private array $domainEvents = [];

    private function __construct(
        private readonly string $id,
    ) {}

    public static function create(string $id): self
    {
        return new self($id);
    }

    public static function reconstitute(string $id): self
    {
        return new self($id);
    }

    public function id(): string { return $this->id; }

    /** @return array<int,object> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }
}
