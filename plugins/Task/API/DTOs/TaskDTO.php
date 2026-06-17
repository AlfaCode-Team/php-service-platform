<?php

declare(strict_types=1);

namespace Plugins\Task\API\DTOs;

use Plugins\Task\Domain\Entities\Task;

final readonly class TaskDTO
{
    public function __construct(
        public string $id,
        public string $title,
        public string $status,
        public string $createdAt,
    ) {}

    public static function fromEntity(Task $task): self
    {
        return new self(
            id:        $task->id()->value(),
            title:     $task->title(),
            status:    $task->status()->value,
            createdAt: $task->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'title'     => $this->title,
            'status'    => $this->status,
            'createdAt' => $this->createdAt,
        ];
    }
}
