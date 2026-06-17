<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Engine;

use AlfaCode\PulseEngine\Contract\EventDispatcherInterface as PulseEventDispatcher;
use AlfaCode\PulseEngine\Event\DomainEvent as PulseDomainEvent;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;

/**
 * KernelEventDispatcher — forwards pulse-engine domain events onto the kernel
 * EventBus as integration events, so engine-emitted events participate in the
 * platform's cross-module event flow.
 *
 * pulse-engine knows nothing about the kernel; this adapter performs the
 * one-way translation (pulse DomainEvent → kernel IntegrationEventContract).
 */
final class KernelEventDispatcher implements PulseEventDispatcher
{
    public function __construct(
        private readonly EventBus $eventBus,
    ) {}

    public function dispatch(PulseDomainEvent $event): void
    {
        $this->eventBus->dispatch(new class ($event) implements IntegrationEventContract {
            public function __construct(
                private readonly PulseDomainEvent $event,
            ) {}

            public function name(): string
            {
                return $this->event->getName();
            }

            public function version(): string
            {
                return '1.0';
            }

            public function payload(): array
            {
                return [
                    'occurredAt' => $this->event->getOccurredAt(),
                ] + get_object_vars($this->event);
            }
        });
    }
}
