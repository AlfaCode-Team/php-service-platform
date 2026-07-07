<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\User\Domain\ValueObjects\UserId;

/**
 * Raised inside the transaction when a user is (soft-)deleted.
 */
final readonly class UserDeletedDomainEvent implements DomainEventContract
{
    public function __construct(
        public UserId $userId,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
