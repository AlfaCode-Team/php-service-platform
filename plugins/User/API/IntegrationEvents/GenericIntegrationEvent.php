<?php

declare(strict_types=1);

namespace Plugins\User\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Carrier used by the outbox relay to re-emit a stored event onto the EventBus.
 *
 * Subscribers register by event NAME (a string), so a faithful name/version/
 * payload carrier dispatches identically to the original typed event — without
 * the relay needing to know every concrete event class.
 */
final readonly class GenericIntegrationEvent implements IntegrationEventContract
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        private string $name,
        private string $version,
        private array $payload,
    ) {}

    public function name(): string { return $this->name; }
    public function version(): string { return $this->version; }

    /** @return array<string,mixed> */
    public function payload(): array { return $this->payload; }
}
