<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

final readonly class VoteCount
{
    private function __construct(private int $value)
    {
        if ($value < 0) {
            throw new \DomainException('VoteCount cannot be negative.');
        }
    }

    public static function of(int $value): self { return new self($value); }

    public static function zero(): self { return new self(0); }

    public function increment(): self { return new self($this->value + 1); }

    public function incrementBy(int $by): self
    {
        if ($by < 1) {
            throw new \DomainException('VoteCount increment must be at least 1.');
        }
        return new self($this->value + $by);
    }

    public function value(): int { return $this->value; }
}
