<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

/**
 * Result of rotating a refresh token: a fresh ACCESS token (the credential the
 * client sends on each request) plus a NEW refresh token (the old one is now
 * revoked — one-time use). `tenantId`/`role` echo the scope baked into the
 * access token's `tnt` claim ('' / null when unscoped).
 */
final readonly class RefreshRotation
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
        public string $refreshExpiresAt,
        public string $tenantId,
        public ?string $role,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'accessToken'      => $this->accessToken,
            'tokenType'        => 'Bearer',
            'expiresIn'        => $this->expiresIn,
            'refreshToken'     => $this->refreshToken,
            'refreshExpiresAt' => $this->refreshExpiresAt,
            'tenantId'         => $this->tenantId,
            'role'             => $this->role,
        ];
    }
}
