<?php

declare(strict_types=1);

namespace Plugins\Task\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Task\API\Contracts\TaskServiceContract;
use Plugins\Task\API\DTOs\CreateTaskDTO;
use Plugins\Task\API\DTOs\TaskDTO;
use Plugins\Task\API\IntegrationEvents\TaskCreatedIntegrationEvent;
use Plugins\Task\Domain\Entities\Task;
use Plugins\Task\Domain\Events\TaskCreatedDomainEvent;
use Plugins\Task\Infrastructure\Persistence\TaskRepository;

final class TaskService implements TaskServiceContract
{
    public function __construct(
        private readonly TaskRepository $repository,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus $eventBus,
        private readonly Identity $identity,
    ) {}

    /** @return list<TaskDTO> */
    public function list(): array
    {
        return array_map(
            static fn(Task $task): TaskDTO => TaskDTO::fromEntity($task),
            $this->repository->all(),
        );
    }

    public function create(CreateTaskDTO $dto): TaskDTO
    {
        $this->collector->beginCollection();
        $this->transaction->begin();

        try {
            $task = Task::create($dto->title);

            foreach ($task->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->save($task);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();
            throw new ServiceException(
                'task.create.failed',
                layer: 'service.task',
                previous: $e,
            );
        }

        $created = $this->firstCreatedEvent($this->collector->release());
        if ($created !== null) {
            $this->eventBus->dispatch(new TaskCreatedIntegrationEvent(
                taskId:     $created->taskId->value(),
                title:      $created->title,
                occurredAt: $created->occurredAt->format(\DateTimeInterface::RFC3339),
            ));
        }

        return TaskDTO::fromEntity($task);
    }

    public function find(string $id): ?TaskDTO
    {
        $task = $this->repository->find($id);
        return $task === null ? null : TaskDTO::fromEntity($task);
    }

    public function complete(string $id): ?TaskDTO
    {
        $task = $this->repository->find($id);
        if ($task === null) {
            return null;
        }

        $this->transaction->begin();
        try {
            $task->markCompleted();
            $this->repository->save($task);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'task.complete.failed',
                layer: 'service.task',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return TaskDTO::fromEntity($task);
    }

    public function delete(string $id): bool
    {
        $this->transaction->begin();
        try {
            $deleted = $this->repository->delete($id);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw new ServiceException(
                'task.delete.failed',
                layer: 'service.task',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return $deleted;
    }

    /**
     * @param list<\AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract> $events
     */
    private function firstCreatedEvent(array $events): ?TaskCreatedDomainEvent
    {
        foreach ($events as $event) {
            if ($event instanceof TaskCreatedDomainEvent) {
                return $event;
            }
        }
        return null;
    }
}
