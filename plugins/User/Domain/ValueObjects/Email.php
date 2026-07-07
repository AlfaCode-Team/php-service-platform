<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * Email — validated, normalised address (the `email` varchar(150) column).
 */
final readonly class Email
{
    private function __construct(private string $value)
    {
        if (mb_strlen($value) > 150) {
            throw new \DomainException('Email must be 150 characters or fewer.');
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Email is not a valid address.');
        }
    }

    public static function fromString(string $value): self
    {
        // Lower-case the address for stable uniqueness (uniq_email).
        return new self(mb_strtolower(trim($value)));
    }

    public function value(): string
    {
        return $this->value;
    }
}
