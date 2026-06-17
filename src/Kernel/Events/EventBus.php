<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\{EventListenerContract, IntegrationEventContract};
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// ─── EventBus ────────────────────────────────────────────────────────────────

/**
 * Dispatches integration events to subscribed module listeners.
 *
 * Subscriptions are registered ONCE during module boot() (app-lifetime) so the
 * routing table is stable and shareable across requests under OpenSwoole.
 *
 * Listener instances are resolved from the supplied PSR-11 container. Listeners
 * must be stateless integration handlers (they receive primitive-only events).
 *
 * Subscriber failures are isolated and logged via PSR-3 — one failing listener
 * never prevents the others from receiving the event.
 *
 * IMPORTANT: dispatch ONLY after a successful transaction commit.
 */
final class EventBus
{
    /** @var array<string, list<class-string<EventListenerContract>>> event name => listener classes */
    private array $subscribers = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Subscribe a listener to an event. Called from Module::boot().
     *
     * @param class-string<EventListenerContract> $listenerClass
     */
    public function subscribe(string $eventName, string $listenerClass): void
    {
        $this->subscribers[$eventName][] = $listenerClass;
    }

    /** Dispatch an integration event to all subscribers, each in isolation. */
    public function dispatch(IntegrationEventContract $event): void
    {
        foreach ($this->subscribers[$event->name()] ?? [] as $listenerClass) {
            try {
                $listener = $this->container->has($listenerClass)
                    ? $this->container->get($listenerClass)
                    : new $listenerClass();
                $listener->handle($event);
            } catch (\Throwable $e) {
                // Isolate subscriber failures — never mask the original dispatch.
                $this->logger->error('EventBus listener failed', [
                    'listener' => $listenerClass,
                    'event'    => $event->name(),
                    'version'  => $event->version(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }
}
