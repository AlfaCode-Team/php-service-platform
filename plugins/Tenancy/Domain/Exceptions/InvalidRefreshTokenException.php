<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a refresh token is unknown, expired, revoked, or (on rotation)
 * belongs to a membership that is no longer active. Maps to HTTP 401 — the
 * client must re-authenticate.
 */
final class InvalidRefreshTokenException extends \RuntimeException
{
    public static function invalid(): self
    {
        return new self('The refresh token is invalid, expired, or revoked.');
    }

    public static function membershipRevoked(): self
    {
        return new self('Access to this tenant has been revoked.');
    }

    /**
     * A revoked token was replayed (or two rotations raced). The whole rotation
     * family has been invalidated — the client must re-authenticate.
     */
    public static function reuseDetected(): self
    {
        return new self('This session has been invalidated. Please sign in again.');
    }
}
