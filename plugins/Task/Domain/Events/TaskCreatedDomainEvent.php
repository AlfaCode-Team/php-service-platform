<?php

declare(strict_types=1);

namespace Plugins\Task\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Task\Domain\ValueObjects\TaskId;

final readonly class TaskCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public TaskId $taskId,
        public string $title,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
