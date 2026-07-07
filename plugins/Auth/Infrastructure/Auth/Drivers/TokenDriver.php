<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Auth\Drivers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;

/** Personal-access-token guard — accepts an api_key Identity from PersonalAccessTokenLayer. */
final class TokenDriver implements GuardDriver
{
    use ResolvesFromVerdict;

    public static function driverName(): string
    {
        return 'token';
    }

    public function resolve(Request $request, GuardContext $context): ?Authenticatable
    {
        return $this->resolveVerdict($request, $context, ['api_key']);
    }
}
