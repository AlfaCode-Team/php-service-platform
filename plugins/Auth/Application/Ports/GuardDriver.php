<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

/**
 * GuardDriver — resolves the current Authenticatable for a request from ONE
 * source (session, jwt, api token, gateway verdict). Concrete drivers live in
 * Infrastructure/Auth/Drivers and are filesystem-scanned by AuthManager, keyed
 * by driverName() — that is the "scan drivers" behaviour.
 */
interface GuardDriver
{
    /** The config `driver` key this class implements: 'session' | 'jwt' | 'token' | 'request'. */
    public static function driverName(): string;

    /** Resolve the current user, or null when this driver can't authenticate the request. */
    public function resolve(Request $request, GuardContext $context): ?Authenticatable;
}
