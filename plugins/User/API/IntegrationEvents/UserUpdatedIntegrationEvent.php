<?php

declare(strict_types=1);

namespace Plugins\User\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Cross-module announcement that a user was updated. Primitives only; carries
 * the changed field names, never the new values (no credential leakage).
 */
final readonly class UserUpdatedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    /** @param list<string> $changed */
    public function __construct(
        public string $userId,
        public array $changed,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'user.updated';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'userId'     => $this->userId,
            'changed'    => $this->changed,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
