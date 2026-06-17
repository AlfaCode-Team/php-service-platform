<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

enum SubscriptionLevel: string
{
    case Free     = 'free';
    case Silver   = 'silver';
    case Gold     = 'gold';
    case Platinum = 'platinum';

    public function key(): int
    {
        return match($this) {
            self::Free     => 0,
            self::Silver   => 1,
            self::Gold     => 2,
            self::Platinum => 3,
        };
    }

    public function defaultDailyVotes(): int
    {
        return match($this) {
            self::Free     => 0,
            self::Silver   => 7,
            self::Gold     => 10,
            self::Platinum => 15,
        };
    }

    public function defaultPrice(): int
    {
        return match($this) {
            self::Free     => 0,
            self::Silver   => 25000,
            self::Gold     => 30000,
            self::Platinum => 35000,
        };
    }

    public function isFree(): bool { return $this === self::Free; }

    public function isHigherThan(self $other): bool
    {
        return $this->key() > $other->key();
    }
}
