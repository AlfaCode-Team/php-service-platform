<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\Rules;

use Plugins\Voting\Domain\Entities\Edition;

final class VotingWindowRule
{
    public static function check(Edition $edition): bool
    {
        if (!$edition->status()->isActive()) {
            return false;
        }

        $now = new \DateTimeImmutable();

        if ($edition->startDate() !== null && $now < $edition->startDate()) {
            return false;
        }

        if ($edition->endDate() !== null && $now > $edition->endDate()) {
            return false;
        }

        return true;
    }
}
