<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Auth\Drivers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;

/** JWT guard — accepts a Bearer-JWT Identity verified by JwtAuthLayer. */
final class JwtDriver implements GuardDriver
{
    use ResolvesFromVerdict;

    public static function driverName(): string
    {
        return 'jwt';
    }

    public function resolve(Request $request, GuardContext $context): ?Authenticatable
    {
        return $this->resolveVerdict($request, $context, ['jwt']);
    }
}
