<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Exceptions;

/**
 * Raised when a refresh token is unknown, expired, or revoked. Maps to HTTP 401
 * — the client must re-authenticate.
 */
final class InvalidRefreshTokenException extends \RuntimeException
{
    public static function invalid(): self
    {
        return new self('The refresh token is invalid, expired, or revoked.');
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
