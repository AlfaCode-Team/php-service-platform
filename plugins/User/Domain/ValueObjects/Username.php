<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * Username — validated handle (the `username` varchar(50) column).
 *
 * Stored case-preserved but compared case-insensitively at the persistence
 * layer (uniq_username). Allows letters, digits, underscore, dot and hyphen.
 */
final readonly class Username
{
    private function __construct(private string $value)
    {
        $len = mb_strlen($value);
        if ($len < 5 || $len > 50) {
            throw new \DomainException('Username must be between 5 and 50 characters.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new \DomainException('Username may only contain letters, digits, dot, underscore and hyphen.');
        }
    }

    public static function fromString(string $value): self
    {
        return new self(trim($value));
    }

    public function value(): string
    {
        return $this->value;
    }
}
