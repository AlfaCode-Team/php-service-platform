<?php

declare(strict_types=1);

namespace Plugins\Task\API\Contracts;

use Plugins\Task\API\DTOs\CreateTaskDTO;
use Plugins\Task\API\DTOs\TaskDTO;

interface TaskServiceContract
{
    /** @return list<TaskDTO> */
    public function list(): array;

    public function create(CreateTaskDTO $dto): TaskDTO;

    public function find(string $id): ?TaskDTO;

    public function complete(string $id): ?TaskDTO;

    public function delete(string $id): bool;
}
