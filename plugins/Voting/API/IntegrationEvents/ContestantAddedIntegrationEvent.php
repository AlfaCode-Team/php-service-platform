<?php

declare(strict_types=1);

namespace Plugins\Voting\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

final class ContestantAddedIntegrationEvent implements IntegrationEventContract
{
    public string $version = '1.0';

    public function __construct(
        public readonly string $contestantId,
        public readonly string $editionId,
        public readonly string $fullName,
        public readonly string $occurredAt,
    ) {}

    public function name(): string    { return 'voting.contestant_added'; }
    public function version(): string { return $this->version; }

    /** @return array<string, mixed> */
    public function payload(): array  { return get_object_vars($this); }
}
