<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a hostname is already registered (by this or another tenant).
 * A hostname maps to exactly one tenant, so a duplicate is rejected up front.
 *
 * Maps to HTTP 409 — do NOT disclose which tenant owns the conflicting host.
 */
final class HostConflictException extends \RuntimeException
{
    public static function for(string $hostname): self
    {
        return new self("Hostname [{$hostname}] is already registered.");
    }
}
