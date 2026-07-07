<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\User\Domain\ValueObjects\Email;
use Plugins\User\Domain\ValueObjects\UserId;
use Plugins\User\Domain\ValueObjects\Username;

/**
 * Raised inside the transaction when a new user is registered. Carries domain
 * value objects only — it never leaves the module (see the integration event
 * for the cross-module, primitives-only counterpart).
 */
final readonly class UserRegisteredDomainEvent implements DomainEventContract
{
    public function __construct(
        public UserId $userId,
        public Username $username,                                                                                                                                                                                                                                           
        public Email $email,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
