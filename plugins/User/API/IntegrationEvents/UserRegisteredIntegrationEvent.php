<?php

declare(strict_types=1);

namespace Plugins\User\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Cross-module announcement that a user registered. Primitives only — other
 * modules may not share this module's value objects. NEVER carries the
 * password hash or remember token.
 */
final readonly class UserRegisteredIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $userId,
        public string $username,
        public string $email,
        public string $occurredAt,
        /** Originating tenant ('' when none) — lets a subscriber assign membership. */
        public string $tenantId = '',
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'user.registered';
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
            'username'   => $this->username,
            'email'      => $this->email,
            'occurredAt' => $this->occurredAt,
            'tenantId'   => $this->tenantId,
            'version'    => $this->version,
        ];
    }
}
