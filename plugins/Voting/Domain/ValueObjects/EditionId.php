<?php

declare(strict_types=1);

namespace Plugins\Voting\Domain\ValueObjects;

final readonly class EditionId
{
    private function __construct(private string $value)
    {
        if ($value === '') {
            throw new \DomainException('EditionId cannot be empty.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(8)));
    }

    public static function from(string $value): self
    {
        return new self($value);
    }

    public function value(): string { return $this->value; }

    public function equals(self $other): bool { return $this->value === $other->value; }
}
