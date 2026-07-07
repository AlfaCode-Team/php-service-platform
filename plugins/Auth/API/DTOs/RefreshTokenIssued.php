<?php

declare(strict_types=1);

namespace Plugins\Auth\API\DTOs;

/**
 * A freshly issued refresh token. The raw `token` is returned ONCE (only its
 * hash is stored). Store it client-side as the long-lived session credential.
 */
final readonly class RefreshTokenIssued
{
    public function __construct(
        public string $tokenId,
        public string $token,
        public string $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['tokenId' => $this->tokenId, 'token' => $this->token, 'expiresAt' => $this->expiresAt];
    }
}
