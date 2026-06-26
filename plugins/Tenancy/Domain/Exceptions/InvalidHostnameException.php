<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Exceptions;

/**
 * Raised when a submitted hostname is syntactically invalid (not a routable
 * domain/subdomain). Maps to HTTP 422.
 */
final class InvalidHostnameException extends \RuntimeException
{
    public static function for(string $hostname): self
    {
        return new self("Hostname [{$hostname}] is not a valid domain or subdomain.");
    }
}
