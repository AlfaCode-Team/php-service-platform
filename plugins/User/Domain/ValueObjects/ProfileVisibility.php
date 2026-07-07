<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * ProfileVisibility — who may see a user's profile (the `profile_visibility`
 * column whitelist).
 */
enum ProfileVisibility: string
{
    case Public   = 'public';
    case Private  = 'private';
    case Contacts = 'contacts';

    public static function fromString(string $value): self
    {
        return self::tryFrom(trim($value))
            ?? throw new \DomainException('Profile visibility must be one of: public, private, contacts.');
    }
}
