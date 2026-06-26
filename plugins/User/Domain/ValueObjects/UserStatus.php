<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * UserStatus — maps the `status` tinyint column (1=active,2=inactive,3=pending).
 */
enum UserStatus: int
{
    case Active   = 1;
    case Inactive = 2;
    case Pending  = 3;

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active   => 'active',
            self::Inactive => 'inactive',
            self::Pending  => 'pending',
        };
    }
}
