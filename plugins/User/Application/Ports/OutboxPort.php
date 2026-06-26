<?php

declare(strict_types=1);

namespace Plugins\User\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Internal port for enqueueing integration events into the transactional outbox
 * (DIP seam — lets the service be tested without a database).
 */
interface OutboxPort
{
    public function write(IntegrationEventContract $event): void;
}
