<?php

declare(strict_types=1);

namespace Plugins\Voting\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

final class SubscriptionUpgradedIntegrationEvent implements IntegrationEventContract
{
    public string $version = '1.0';

    public function __construct(
        public readonly string $userId,
        public readonly string $editionId,
        public readonly string $fromLevel,
        public readonly string $toLevel,
        public readonly string $occurredAt,
    ) {}

    public function name(): string    { return 'voting.subscription_upgraded'; }
    public function version(): string { return $this->version; }

    /** @return array<string, mixed> */
    public function payload(): array  { return get_object_vars($this); }
}
