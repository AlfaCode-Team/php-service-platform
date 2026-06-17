<?php

declare(strict_types=1);

namespace Plugins\Task\Domain\Entities;

use Plugins\Task\Domain\Events\TaskCreatedDomainEvent;
use Plugins\Task\Domain\ValueObjects\TaskId;
use Plugins\Task\Domain\ValueObjects\TaskStatus;

final class Task
{
    /** @var list<TaskCreatedDomainEvent> */
    private array $domainEvents = [];

    private function __construct(
        private readonly TaskId $id,
        private string $title,
        private TaskStatus $status,
        private readonly \DateTimeImmutable $createdAt,
    ) {}

    public static function create(string $title): self
    {
        $title = trim($title);
        if ($title === '') {
            throw new \DomainException('Task title cannot be empty.');
        }

        $task = new self(
            id:        TaskId::generate(),
            title:     $title,
            status:    TaskStatus::Pending,
            createdAt: new \DateTimeImmutable(),
        );

        $task->domainEvents[] = new TaskCreatedDomainEvent(
            taskId:     $task->id,
            title:      $task->title,
            occurredAt: $task->createdAt,
        );

        return $task;
    }

    public static function reconstitute(
        string $id,
        string $title,
        string $status,
        string $createdAt,
    ): self {
        return new self(
            id:        TaskId::fromString($id),
            title:     $title,
            status:    TaskStatus::from($status),
            createdAt: new \DateTimeImmutable($createdAt),
        );
    }

    public function markCompleted(): void
    {
        if ($this->status->isDone()) {
            throw new \DomainException('Task is already completed.');
        }
        $this->status = TaskStatus::Done;
    }

    /** @return list<TaskCreatedDomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    public function id(): TaskId                          { return $this->id; }
    public function title(): string                       { return $this->title; }
    public function status(): TaskStatus                  { return $this->status; }
    public function createdAt(): \DateTimeImmutable       { return $this->createdAt; }
}
