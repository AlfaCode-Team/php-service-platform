<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Exceptions;

/**
 * Thrown when a token is valid but lacks a required scope/ability. `scopes()`
 * returns the scopes that were required so the caller can report them. Port of
 * the old __DEV__ MissingScopeException.
 */
final class MissingScopeException extends AuthorizationException
{
    /** @var list<string> */
    private array $scopes;

    /** @param list<string>|string $scopes one or more required scope names */
    public function __construct(array|string $scopes = [], string $message = 'Invalid scope(s) provided.')
    {
        $this->scopes = is_array($scopes) ? array_values($scopes) : [$scopes];

        parent::__construct($message);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }
}
