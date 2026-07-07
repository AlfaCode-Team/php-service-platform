<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;

/**
 * Thrown when an authenticated caller is denied by a gate/policy check.
 *
 * GDA-native port of the old __DEV__ AuthorizationException. Maps to HTTP 403 by
 * default; `asNotFound()` masks the resource as 404 (avoids leaking existence).
 */
class AuthorizationException extends SecurityException
{
    private ?int $status = null;

    public function __construct(
        string $message = 'This action is unauthorized.',
        int|string $appCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, layer: 'auth.authorization', context: ['app_code' => $appCode], code: 403, previous: $previous);
    }

    /** Override the HTTP status the pipeline should emit (e.g. 404 to mask). */
    public function withStatus(?int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** Deny as 404 so the resource's existence is not revealed. */
    public function asNotFound(): static
    {
        return $this->withStatus(404);
    }

    public function hasStatus(): bool
    {
        return $this->status !== null;
    }

    public function status(): ?int
    {
        return $this->status;
    }
}
