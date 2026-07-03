<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * DeviceCode — a device authorization grant request (RFC 8628).
 *
 * The device polls the token endpoint with the (hashed) device_code while the
 * user approves out-of-band by entering the short user_code at the verification
 * URI. Only the device_code hash is stored; the user_code is shown to the user.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 */
final class DeviceCode extends Entity
{
    public const PENDING    = 'pending';
    public const AUTHORIZED = 'authorized';
    public const DENIED     = 'denied';

    /** @param list<string> $scopes */
    public static function of(
        string $id,
        string $userCode,
        string $clientId,
        array $scopes,
        string $status,
        ?string $userId,
        int $interval,
        ?\DateTimeImmutable $lastPolledAt,
        \DateTimeImmutable $expiresAt,
    ): self {
        $d = (new self())->forceFill([
            'id'           => $id,
            'userCode'     => $userCode,
            'clientId'     => $clientId,
            'scopes'       => $scopes,
            'status'       => $status,
            'userId'       => $userId,
            'interval'     => $interval,
            'lastPolledAt' => $lastPolledAt,
            'expiresAt'    => $expiresAt,
        ]);
        $d->syncOriginal();

        return $d;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }
}
