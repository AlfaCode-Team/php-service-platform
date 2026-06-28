<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

/**
 * DeviceCode — a device authorization grant request (RFC 8628).
 *
 * The device polls the token endpoint with the (hashed) device_code while the
 * user approves out-of-band by entering the short user_code at the verification
 * URI. Only the device_code hash is stored; the user_code is shown to the user.
 */
final class DeviceCode
{
    public const PENDING    = 'pending';
    public const AUTHORIZED = 'authorized';
    public const DENIED     = 'denied';

    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly string $userCode,
        public readonly string $clientId,
        public readonly array $scopes,
        public readonly string $status,
        public readonly ?string $userId,
        public readonly int $interval,
        public readonly ?\DateTimeImmutable $lastPolledAt,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
