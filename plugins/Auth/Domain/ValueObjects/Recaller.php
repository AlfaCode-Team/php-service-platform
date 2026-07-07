<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\ValueObjects;

/**
 * Recaller — the parsed "remember me" cookie value.
 *
 * Ported verbatim (concept) from the old __DEV__/Auth Recaller: a plain
 * pipe-delimited `userId|token` string. NEVER unserialize user-controlled cookie
 * input — the recaller is always this flat string, so PHP object injection is
 * impossible. Zero external dependencies (Domain layer rule).
 */
final readonly class Recaller
{
    /** @var list<string> segments from a single explode */
    private array $parts;

    public function __construct(private string $value)
    {
        $this->parts = explode('|', $value, 2);
    }

    /** Compose the raw cookie value from its two segments. */
    public static function make(string $userId, string $token): self
    {
        return new self($userId . '|' . $token);
    }

    public function id(): string
    {
        return $this->parts[0] ?? '';
    }

    public function token(): string
    {
        return $this->parts[1] ?? '';
    }

    /** Both segments present and non-blank, and the delimiter was actually there. */
    public function valid(): bool
    {
        return str_contains($this->value, '|')
            && count($this->parts) === 2
            && trim($this->parts[0]) !== ''
            && trim($this->parts[1]) !== '';
    }

    public function value(): string
    {
        return $this->value;
    }
}
