<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\RefreshTokenRecord;

/**
 * Internal persistence port for `refresh_tokens` (central). DIP seam so
 * RefreshTokenService is unit-testable without a database.
 */
interface RefreshTokenStore
{
    public function store(
        string $tokenId,
        string $familyId,
        string $userId,
        string $tokenHash,
        ?string $tenantId,
        ?string $device,
        ?string $ip,
        \DateTimeImmutable $expiresAt,
    ): void;

    /** Active = not revoked AND not past expiry. Null otherwise. */
    public function findActiveByHash(string $tokenHash): ?RefreshTokenRecord;

    /**
     * Lookup by hash REGARDLESS of revoked state (but still within expiry) so the
     * service can distinguish "unknown" from "already revoked" (= reuse/replay).
     */
    public function findByHash(string $tokenHash): ?RefreshTokenRecord;

    public function revoke(string $tokenId): void;

    /**
     * Atomically revoke a token only if it is currently active. Returns true when
     * THIS call performed the transition — false means it was already revoked
     * (lost a rotation race / replay), the caller's signal to treat it as reuse.
     */
    public function revokeIfActive(string $tokenId): bool;

    /** Revoke every active token in a rotation family (reuse-detection response). */
    public function revokeFamily(string $familyId): int;

    /** Revoke every active token for a user (logout-everywhere / compromise). */
    public function revokeAllForUser(string $userId): int;
}
