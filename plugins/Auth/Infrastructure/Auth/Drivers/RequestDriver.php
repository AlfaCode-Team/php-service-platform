<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Auth\Drivers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;

/**
 * Request guard — credential-agnostic: rehydrates whatever Identity the
 * SecurityGateway attached (jwt, api_key, or session), regardless of type. The
 * catch-all guard for routes that just want "the current user, however they
 * authenticated".
 */
final class RequestDriver implements GuardDriver
{
    use ResolvesFromVerdict;

    public static function driverName(): string
    {
        return 'request';
    }

    public function resolve(Request $request, GuardContext $context): ?Authenticatable
    {
        return $this->resolveVerdict($request, $context, ['jwt', 'api_key', 'session']);
    }
}
