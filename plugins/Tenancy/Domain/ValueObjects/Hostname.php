<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

use Plugins\Tenancy\Domain\Exceptions\InvalidHostnameException;

/**
 * Hostname — a validated, normalised domain or subdomain.
 *
 * Normalisation (lower-case, strip port, strip trailing dot, strip a leading
 * scheme/path the UI might submit) is centralised here so the management API,
 * the routing registry, and DNS scanning all key on the SAME canonical string.
 *
 * Domain layer: zero external imports beyond Domain/.
 */
final readonly class Hostname
{
    private function __construct(
        public string $value,
    ) {}

    /** @throws InvalidHostnameException */
    public static function of(string $raw): self
    {
        $host = self::normalise($raw);

        if (!self::isValid($host)) {
            throw InvalidHostnameException::for($raw);
        }

        return new self($host);
    }

    /** Lower-case, scheme/path/port/trailing-dot stripped. NO validation. */
    public static function normalise(string $raw): string
    {
        $host = strtolower(trim($raw));
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $host) ?? $host; // strip scheme
        $host = explode('/', $host)[0];                                       // strip path
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;                  // strip port
        $host = preg_replace('/^\*\./', '', $host) ?? $host;                  // strip wildcard label

        return trim($host, '.');                                             // strip trailing/leading dot
    }

    /** RFC-1123 host: labels of [a-z0-9-], ≤63 each, ≤253 total, ≥2 labels. */
    public static function isValid(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || !str_contains($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
                return false;
            }
        }

        // Reject a bare IP literal — hosts must be names.
        return filter_var($host, FILTER_VALIDATE_IP) === false;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
