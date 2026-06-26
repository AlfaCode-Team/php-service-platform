<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\InvitationResult;

/**
 * InvitationServiceContract — email-based tenant onboarding.
 *
 * Invitations decouple "invited" from "has an account": the invite stores an
 * email + role; accepting it (by an authenticated user whose verified email
 * matches) converts it into an active `user_tenants` seat.
 */
interface InvitationServiceContract
{
    /**
     * Create a pending invitation and return the one-time raw token.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException
     *         when a pending invite already exists for (tenant, email)
     */
    public function invite(
        string $tenantId,
        string $email,
        string $role,
        string $invitedBy,
        int $ttlSeconds = 604800,
    ): InvitationResult;

    /**
     * Accept an invitation: validate the token (pending, not expired, email
     * matches), create/activate the seat, mark the invite accepted, audit
     * `member.join`. Returns the tenant id joined.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\InvalidInvitationException (→ 422)
     */
    public function accept(string $rawToken, string $userId, string $userEmail, ?string $ip = null): string;

    /** Revoke a pending invitation (no longer acceptable). */
    public function revoke(string $rawToken): void;
}
