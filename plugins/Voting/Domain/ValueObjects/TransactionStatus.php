<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

enum TransactionStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Failed    = 'failed';

    public function isPending(): bool   { return $this === self::Pending; }
    public function isCompleted(): bool { return $this === self::Completed; }
}
