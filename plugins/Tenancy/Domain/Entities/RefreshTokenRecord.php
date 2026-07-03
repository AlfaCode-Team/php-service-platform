<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Project\Support\Entity\Entity;

/**
 * RefreshTokenRecord — the persisted, non-secret facts about an active refresh
 * token (the hash itself is matched in the query, never returned). `tenantId` is
 * null/'' when the token is unscoped (issued at login, before tenant selection).
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
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
            // A pre-migration row with no family falls back to its own token_id
            // so it forms a single-member family rather than a null lineage.
            'familyId' => $family !== '' ? $family : (string) $row['token_id'],
            'revoked'  => \array_key_exists('revoked_at', $row) && $row['revoked_at'] !== null,
        ]);
        $r->syncOriginal();

        return $r;
    }
}
