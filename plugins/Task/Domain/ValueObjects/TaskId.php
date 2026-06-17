<?php

declare(strict_types=1);

namespace Plugins\Task\Domain\ValueObjects;

final readonly class TaskId
{
    private function __construct(private string $value)
    {
        if ($value === '') {
            throw new \DomainException('TaskId cannot be empty.');
        }
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(8)));
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
