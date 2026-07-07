<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\Domain\Exceptions\NotAMemberException;
use Project\Http\Controllers\ApiController;

/**
 * Thin HTTP boundary for the tenant-selection flow — DTO/service → Response.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is $this->resolveRequest(). The user id always comes from the verified
 * Identity, never from the request body — a client cannot act as another user.
 */
final class TenantController extends ApiController
{
    public function __construct(
        private readonly MembershipServiceContract $memberships,
    ) {}

    /** GET /ajx/me/tenants — the tenant picker for the authenticated user. */
    public function mine(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        $tenants = $this->memberships->myTenants($identity->userId);

        return $this->ok(['data' => array_map(static fn ($t) => $t->toArray(), $tenants)]);
    }

    /** POST /ajx/tenants/{tenantId}/select — re-mint a tenant-scoped token. */
    public function select(string $tenantId): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }

        try {
            $selection = $this->memberships->selectTenant(
                $identity->userId,
                $tenantId,
                $this->resolveRequest()->ip(),
            );
        } catch (NotAMemberException) {
            return $this->forbidden('You are not an active member of this tenant.');
        }

        return $this->ok($selection->toArray());
    }
}
