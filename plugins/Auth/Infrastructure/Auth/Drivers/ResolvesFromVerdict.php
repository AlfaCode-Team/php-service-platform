<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Auth\Drivers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Auth\Application\Auth\AuthUserProxy;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;

/**
 * Shared resolution for the stateless (token) guards. The SecurityGateway has
 * already VERIFIED the credential and attached an Identity to the Request; these
 * drivers just rehydrate it into a rich Authenticatable via the provider,
 * carrying the verdict's roles/permissions/tenant. No re-verification, no crypto
 * duplicated out of the gateway.
 */
trait ResolvesFromVerdict
{
    /** Resolve only when the gateway Identity's tokenType is in $accept. */
    private function resolveVerdict(Request $request, GuardContext $context, array $accept): ?Authenticatable
    {
        $identity = $request->identity();
        if ($identity === null || $identity->isGuest() || !in_array($identity->tokenType, $accept, true)) {
            return null;
        }

        $user = $context->provider->retrieveById($identity->userId);
        if (!$user instanceof AuthUserProxy) {
            return $user;
        }

        return $user->withSecurity(
            $identity->roles,
            $identity->permissions,
            $identity->tenantId,
            $identity->tokenType,
        );
    }
}
