<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Exceptions;

/**
 * A supplied token was structurally valid but rejected (wrong owner, expired,
 * revoked). Named constructors mirror the old __DEV__ InvalidAuthTokenException.
 */
final class InvalidAuthTokenException extends AuthenticationException
{
    public static function different(): self
    {
        return new self('The provided token does not match the expected owner.');
    }

    public static function expired(): self
    {
        return new self('The provided token has expired.');
    }

    public static function revoked(): self
    {
        return new self('The provided token has been revoked.');
    }
}
