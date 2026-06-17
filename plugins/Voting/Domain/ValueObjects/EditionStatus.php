<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

enum EditionStatus: string
{
    case Draft  = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public function isActive(): bool { return $this === self::Active; }
    public function isDraft(): bool  { return $this === self::Draft; }
    public function isClosed(): bool { return $this === self::Closed; }
}
