<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when an invitation token is unknown, already used, revoked, or expired.
 * Maps to HTTP 422 — the link is no longer valid.
 */
final class InvalidInvitationException extends \RuntimeException
{
    public static function notUsable(): self
    {
        return new self('This invitation is invalid, already used, or expired.');
    }

    public static function emailMismatch(): self
    {
        return new self('This invitation was issued for a different email address.');
    }
}
