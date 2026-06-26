<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * UserId — the public ULID identifier (the `user_id` char(31) column).
 *
 * This is the cross-boundary identifier exposed to the outside world; the
 * table's auto-increment `id` is an internal surrogate key that NEVER leaves
 * the persistence layer.
 */
final readonly class UserId
{
    private function __construct(private string $value)
    {
        if ($value === '' || mb_strlen($value) > 31) {
            throw new \DomainException('UserId must be 1-31 characters.');
        }
    }

    /** Generate a 26-char monotonic Crockford ULID (time-ordered, fits char(31)). */
    public static function generate(): self
    {
        return new self(Ulid::generate());
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
