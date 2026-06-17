<?php

declare(strict_types=1);

namespace Plugins\Task\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

final readonly class TaskCreatedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $taskId,
        public string $title,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'task.created';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, string> */
    public function payload(): array
    {
        return [
            'taskId'     => $this->taskId,
            'title'      => $this->title,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
