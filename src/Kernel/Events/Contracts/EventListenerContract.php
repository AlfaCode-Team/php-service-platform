<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts;

interface EventListenerContract
{
    public function handle(IntegrationEventContract $event): void;
}
