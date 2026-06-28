<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

use Plugins\OAuth2\Domain\Entities\RefreshToken;

interface RefreshTokenStore
{
    public function store(RefreshToken $token, string $tokenHash): void;

    public function findByHash(string $tokenHash): ?RefreshToken;

    /** Atomically revoke if currently active; false when it was already revoked. */
    public function revokeIfActive(string $tokenId): bool;

    /** Revoke every token in a rotation family (reuse-detection response). */
    public function revokeFamily(string $familyId): int;

    public function deleteExpired(?\DateTimeImmutable $now = null): int;
}
