<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use Plugins\User\Application\Ports\OutboxPort;

/** Records what the service writes to the outbox. */
final class FakeOutbox implements OutboxPort
{
    /** @var list<IntegrationEventContract> */
    public array $events = [];

    public function write(IntegrationEventContract $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn(IntegrationEventContract $e) => $e->name(), $this->events);
    }
}
