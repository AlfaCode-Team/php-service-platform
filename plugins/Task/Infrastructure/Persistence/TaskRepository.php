<?php

declare(strict_types=1);

namespace Plugins\Task\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Task\Domain\Entities\Task;

final class TaskRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity $identity,
    ) {
        $this->ensureSchema();
    }

    /** @return list<Task> */
    public function all(): array
    {
        try {
            $rows = $this->db->query(
                'SELECT id, title, status, created_at FROM tasks
                 WHERE tenant_id = :tenant
                 ORDER BY created_at DESC',
                ['tenant' => $this->tenant()],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to list tasks.',
                layer: 'repository.task',
                previous: $e,
            );
        }

        return array_map(static fn(array $row): Task => self::hydrate($row), $rows);
    }

    public function find(string $id): ?Task
    {
        try {
            $row = $this->db->queryOne(
                'SELECT id, title, status, created_at FROM tasks
                 WHERE id = :id AND tenant_id = :tenant',
                ['id' => $id, 'tenant' => $this->tenant()],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load task [{$id}].",
                layer: 'repository.task',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function save(Task $task): void
    {
        try {
            $this->db->execute(
                'INSERT INTO tasks (id, tenant_id, title, status, created_at)
                 VALUES (:id, :tenant, :title, :status, :created_at)
                 ON CONFLICT(id) DO UPDATE SET title = :title, status = :status',
                [
                    'id'         => $task->id()->value(),
                    'tenant'     => $this->tenant(),
                    'title'      => $task->title(),
                    'status'     => $task->status()->value,
                    'created_at' => $task->createdAt()->format(\DateTimeInterface::RFC3339),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save task [{$task->id()->value()}].",
                layer: 'repository.task',
                context: ['id' => $task->id()->value()],
                previous: $e,
            );
        }
    }

    public function delete(string $id): bool
    {
        try {
            $affected = $this->db->execute(
                'DELETE FROM tasks WHERE id = :id AND tenant_id = :tenant',
                ['id' => $id, 'tenant' => $this->tenant()],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to delete task [{$id}].",
                layer: 'repository.task',
                context: ['id' => $id],
                previous: $e,
            );
        }

        return $affected > 0;
    }

    private function ensureSchema(): void
    {
        try {
            $this->db->execute(
                'CREATE TABLE IF NOT EXISTS tasks (
                    id         TEXT NOT NULL PRIMARY KEY,
                    tenant_id  TEXT NOT NULL DEFAULT \'\',
                    title      TEXT NOT NULL,
                    status     TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )'
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to initialise tasks schema.',
                layer: 'repository.task',
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Task
    {
        return Task::reconstitute(
            id:        (string) $row['id'],
            title:     (string) $row['title'],
            status:    (string) $row['status'],
            createdAt: (string) $row['created_at'],
        );
    }

    private function tenant(): string
    {
        return $this->identity->tenantId;
    }
}
