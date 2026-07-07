<?php

declare(strict_types=1);

namespace Plugins\User\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Cross-module announcement that a user was (soft-)deleted. Primitives only.
 */
final readonly class UserDeletedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $userId,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'user.deleted';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, string> */
    public function payload(): array
    {
        return [
            'userId'     => $this->userId,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
