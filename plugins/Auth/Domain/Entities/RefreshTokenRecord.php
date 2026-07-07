<?php

declare(strict_types=1);

namespace Plugins\Auth\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * RefreshTokenRecord — the persisted, non-secret facts about an active
 * first-party session refresh token (the hash itself is matched in the query,
 * never returned). `tenantId` is a passthrough scope hint baked into the paired
 * access token's `tnt` claim; it is NOT re-verified on rotation (tenant seat
 * checks live in the Tenancy selection flow).
 *
 * Built on the shared {@see Entity} attribute-bag base.
 */
final class RefreshTokenRecord extends Entity
{
    protected string $primaryKey = 'tokenId';

    public static function of(
        string $tokenId,
        string $userId,
        ?string $tenantId,
        string $familyId = '',
        bool $revoked = false,
    ): self {
        $r = (new self())->forceFill([
            'tokenId'  => $tokenId,
            'userId'   => $userId,
            'tenantId' => ($tenantId === null || $tenantId === '') ? null : $tenantId,
            'familyId' => $familyId !== '' ? $familyId : $tokenId,
            'revoked'  => $revoked,
        ]);
        $r->syncOriginal();

        return $r;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $tenant = isset($row['tenant_id']) ? (string) $row['tenant_id'] : null;
        $family = isset($row['family_id']) ? (string) $row['family_id'] : '';

        $r = (new self())->forceFill([
            'tokenId'  => (string) $row['token_id'],
            'userId'   => (string) $row['user_id'],
            'tenantId' => ($tenant === null || $tenant === '') ? null : $tenant,
            'familyId' => $family !== '' ? $family : (string) $row['token_id'],
            'revoked'  => \array_key_exists('revoked_at', $row) && $row['revoked_at'] !== null,
        ]);
        $r->syncOriginal();

        return $r;
    }
}
