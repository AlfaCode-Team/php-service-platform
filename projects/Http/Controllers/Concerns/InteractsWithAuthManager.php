<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use Plugins\Auth\Application\Auth\AuthManager;
use Plugins\Auth\Application\Auth\GuardAccessor;
use Plugins\Auth\Application\Ports\Authenticatable;

/**
 * InteractsWithAuthManager — controller access to the multi-guard AuthManager.
 *
 * Complements InteractsWithAuth (the lightweight Identity projection): use THIS
 * when you need named guards or the rich DB-backed user object, e.g.
 * `$this->auth('api')->user()` or `$this->authUser()`. The manager is resolved
 * from the request container and bound to the active request on each call.
 *
 * A route using this MUST load the Auth module: `"requires": ["auth.identity"]`.
 */
trait InteractsWithAuthManager
{
    use HasRequest;

    /** The AuthManager, bound to the current request. */
    protected function authManager(): AuthManager
    {
        $request   = $this->resolveRequest();
        $container = $request->container();
        if ($container === null || !$container->has(AuthManager::class)) {
            throw new \RuntimeException('AuthManager unavailable — the route must require "auth.identity".');
        }

        return $container->make(AuthManager::class)->setRequest($request);
    }

    /** A named guard accessor (default guard when null). */
    protected function auth(?string $guard = null): GuardAccessor
    {
        return $this->authManager()->guard($guard);
    }

    /** The current authenticated user via the default (or named) guard. */
    protected function authUser(?string $guard = null): ?Authenticatable
    {
        return $this->authManager()->guard($guard)->user();
    }
}
