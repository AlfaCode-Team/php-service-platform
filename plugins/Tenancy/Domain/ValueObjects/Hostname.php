<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\ValueObjects;

use Plugins\Tenancy\Domain\Exceptions\InvalidHostnameException;

/**
 * Hostname — a validated, normalised domain, subdomain or IP literal.
 *
 * Normalisation (lower-case, strip port, strip trailing dot, strip a leading
 * scheme/path the UI might submit, unwrap [IPv6] brackets) is centralised here
 * so the management API, the routing registry, and DNS scanning all key on the
 * SAME canonical string.
 *
 * Accepts DNS names ("app.example.com"), single-label names ("localhost") and
 * IP literals ("127.0.0.1", "::1") so tenants can be pinned to loopback / direct
 * IP hosts for local development and IP-based deployments.
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

        // Unwrap a bracketed IPv6 literal ([::1] / [::1]:8080 → ::1).
        if (preg_match('/^\[(.+?)\](?::\d+)?$/', $host, $m) === 1) {
            $host = $m[1];
        } elseif (filter_var($host, FILTER_VALIDATE_IP) === false) {
            // Strip a :port from names/IPv4 only — never from a bare IPv6 literal.
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        $host = preg_replace('/^\*\./', '', $host) ?? $host;                  // strip wildcard label

        return trim($host, '.');                                             // strip trailing/leading dot
    }

    /**
     * Valid when the host is an IP literal (v4/v6) OR a DNS name of one or more
     * labels ([a-z0-9-], ≤63 each, ≤253 total) — so "localhost", "127.0.0.1",
     * "::1" and "app.example.com" all pass.
     */
    public static function isValid(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        // Accept IP literals — hosts may be pinned to a loopback / direct IP.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
