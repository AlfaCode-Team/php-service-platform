<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Voting\Domain\ValueObjects\EditionId;
use Plugins\Voting\Domain\ValueObjects\SubscriptionLevel;

final readonly class SubscriptionUpgradedDomainEvent implements DomainEventContract
{
    public function __construct(
        public readonly string             $userId,
        public readonly EditionId          $editionId,
        public readonly SubscriptionLevel  $fromLevel,
        public readonly SubscriptionLevel  $toLevel,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
