<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\Invitation;

/**
 * Internal persistence port for `tenant_invitations` (central). DIP seam so
 * InvitationService is unit-testable without a database.
 */
interface InvitationStore
{
    public function create(
        string $inviteId,
        string $tenantId,
        string $email,
        string $role,
        string $tokenHash,
        string $invitedBy,
        \DateTimeImmutable $expiresAt,
    ): void;

    /** Look up an invitation by the SHA-256 of its token, or null. */
    public function findByTokenHash(string $tokenHash): ?Invitation;

    /** True when a still-pending invite already exists for (tenant, email). */
    public function pendingExists(string $tenantId, string $email): bool;

    public function markAccepted(string $inviteId): void;

    public function markRevoked(string $inviteId): void;
}
