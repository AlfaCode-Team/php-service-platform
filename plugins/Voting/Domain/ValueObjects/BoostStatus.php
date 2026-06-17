<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

enum BoostStatus: string
{
    case Pending   = 'pending';
    case Confirmed = 'confirmed';

    public function isPending(): bool   { return $this === self::Pending; }
    public function isConfirmed(): bool { return $this === self::Confirmed; }
}
