<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Auth\API\Guard;

/**
 * InteractsWithAuth — controller ergonomics over the request Identity.
 *
 * The GDA-native replacement for reaching into an AuthManager / `Auth::` facade
 * from a controller. The SecurityGateway chain already resolved the caller, so
 * these helpers are a thin, allocation-cheap projection over the immutable
 * Request — no globals, no per-request driver state.
 *
 * Requires the HasRequest concern (both base controllers provide it). A route
 * using $this->guard()/user() needs the Auth plugin in its graph
 * (`"requires": ["auth.identity"]`) only if it also wants token issuance; the
 * Guard itself reads the kernel Identity and works even without Auth loaded.
 */
trait InteractsWithAuth
{
    use HasRequest;

    /** Read-only Guard over the current (or an explicit) request. */
    protected function guard(?Request $request = null): Guard
    {
        return Guard::fromRequest($this->resolveRequest($request));
    }

    /** The resolved Identity (guest Identity when unauthenticated). */
    protected function identity(?Request $request = null): Identity
    {
        return $this->resolveRequest($request)->identity() ?? Identity::guest();
    }

    /** True when a non-guest Identity authenticated this request. */
    protected function authCheck(?Request $request = null): bool
    {
        return $this->guard($request)->check();
    }

    /** The authenticated user id, or '' for a guest. */
    protected function authId(?Request $request = null): string
    {
        return $this->guard($request)->id();
    }

    /** Token/scope check — equivalent to the old $token->can($scope). */
    protected function tokenCan(string $scope, ?Request $request = null): bool
    {
        return $this->guard($request)->hasScope($scope);
    }
}
