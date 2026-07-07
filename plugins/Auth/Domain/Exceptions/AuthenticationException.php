<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;

/**
 * Thrown when a request could not be authenticated (no/invalid credential).
 *
 * GDA-native port of the old __DEV__ AuthenticationException. Extends the kernel
 * SecurityException so the ErrorPipeline maps it to HTTP 401 with `warning`
 * severity. Carries the guard name(s) that were tried so a redirect target can
 * be chosen, mirroring the original.
 */
class AuthenticationException extends SecurityException
{
    /** @param list<string> $guards guards that failed to authenticate */
    public function __construct(
        string $message = 'Unauthenticated.',
        public readonly array $guards = [],
        public readonly ?string $redirectTo = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, layer: 'auth.authentication', context: ['guards' => $guards], code: 401, previous: $previous);
    }

    /** @return list<string> */
    public function guards(): array
    {
        return $this->guards;
    }

    public function redirectTo(): ?string
    {
        return $this->redirectTo;
    }
}
