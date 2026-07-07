<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * Theme — UI theme preference (the `theme` column whitelist).
 */
enum Theme: string
{
    case Light  = 'light';
    case Dark   = 'dark';
    case System = 'system';

    public static function fromString(string $value): self
    {
        return self::tryFrom(trim($value))
            ?? throw new \DomainException('Theme must be one of: light, dark, system.');
    }
}
