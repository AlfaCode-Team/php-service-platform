<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\Membership;

/**
 * Internal persistence port for reading central `user_tenants` (DIP seam — lets
 * MembershipService be unit-tested without a database). Not part of the public
 * API; consumers use MembershipServiceContract.
 */
interface MembershipReader
{
    /**
     * Active, routable memberships for a user (seat active AND tenant active).
     *
     * @return list<Membership>
     */
    public function activeForUser(string $userId): array;

    /** A single membership (any status) for (user, tenant), or null. */
    public function find(string $userId, string $tenantId): ?Membership;
}
