<?php

declare(strict_types=1);

namespace Plugins\User\Domain\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\DomainException as FrameworkDomainException;

/**
 * Raised when a username/email already exists. Maps to HTTP 409/422 — distinct
 * from a generic 500 so callers can react (the unique index is the real guard;
 * this exception is how a TOCTOU race surfaces cleanly).
 */
final class DuplicateUserException extends FrameworkDomainException
{
    /** @param list<string> $fields */
    public function __construct(public readonly array $fields = ['username', 'email'])
    {
        parent::__construct(
            'A user with that username or email already exists.',
            layer: 'domain.user',
            context: ['fields' => $fields],
        );
    }
}
