<?php

declare(strict_types=1);

namespace Plugins\Cookie\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Cookie\Infrastructure\CookieJar;

/**
 * Flushes any cookies queued in the request-scoped CookieJar onto the outgoing
 * response (GDA rewrite of the 0.3 AddQueuedCookiesToResponse + EncryptCookies
 * filters — encryption happens inside CookieJar::applyTo()).
 *
 * Registered at `after.load` so the CookieJar bound by the Cookie provider is
 * resolvable from the request-scoped container.
 */
final class QueuedCookiesStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();
        if ($container === null || !$container->has(CookieJar::class)) {
            return $next($request);
        }

        $response = $next($request);

        $jar = $container->make(CookieJar::class);
        return $jar instanceof CookieJar ? $jar->applyTo($response) : $response;
    }
}
