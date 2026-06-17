<?php

declare(strict_types=1);

namespace Plugins\Task\Domain\ValueObjects;

enum TaskStatus: string
{
    case Pending = 'pending';
    case Done    = 'done';

    public function isDone(): bool
    {
        return $this === self::Done;
    }
}
