<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

/**
 * RefreshTokenRecord — the persisted, non-secret facts about an active refresh
 * token (the hash itself is matched in the query, never returned). `tenantId` is
 * null/'' when the token is unscoped (issued at login, before tenant selection).
 */
final readonly class RefreshTokenRecord
{
    public function __construct(
        public string $tokenId,
        public string $userId,
        public ?string $tenantId,
        public string $familyId = '',
        public bool $revoked = false,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $tenant = isset($row['tenant_id']) ? (string) $row['tenant_id'] : null;
        $family = isset($row['family_id']) ? (string) $row['family_id'] : '';

        return new self(
            tokenId:  (string) $row['token_id'],
            userId:   (string) $row['user_id'],
            tenantId: ($tenant === null || $tenant === '') ? null : $tenant,
            // A pre-migration row with no family falls back to its own token_id
            // so it forms a single-member family rather than a null lineage.
            familyId: $family !== '' ? $family : (string) $row['token_id'],
            revoked:  \array_key_exists('revoked_at', $row) && $row['revoked_at'] !== null,
        );
    }
}
