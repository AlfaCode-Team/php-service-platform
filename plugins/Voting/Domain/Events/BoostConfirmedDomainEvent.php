<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Voting\Domain\ValueObjects\ContestantId;
use Plugins\Voting\Domain\ValueObjects\EditionId;

final readonly class BoostConfirmedDomainEvent implements DomainEventContract
{
    public function __construct(
        public readonly string             $boostId,
        public readonly string             $userId,
        public readonly ContestantId       $contestantId,
        public readonly EditionId          $editionId,
        public readonly int                $boostedVotes,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
