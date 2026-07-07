<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\User\Domain\ValueObjects\UserId;

/**
 * Raised inside the transaction when an existing user is modified. Carries the
 * list of changed field names (never the new values — a password change must
 * not leak the credential into the event stream).
 */
final readonly class UserUpdatedDomainEvent implements DomainEventContract
{
    /** @param list<string> $changed */
    public function __construct(
        public UserId $userId,
        public array $changed,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
