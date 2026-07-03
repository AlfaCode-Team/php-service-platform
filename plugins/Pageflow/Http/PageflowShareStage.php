<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;

/**
 * Populates Pageflow shared props on every render — the GDA replacement for the
 * legacy `do_action('pageflow_share')` hook.
 *
 * Runs at after.load (once the Pageflow module has registered its responder). If
 * the request does not have the responder in scope (a non-Pageflow route) or the
 * project bound no PageflowSharerContract, it is a cheap pass-through: shared data
 * stays request-scoped and never leaks between requests under OpenSwoole.
 */
final class PageflowShareStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();

        if ($container !== null
            && $container->has(PageflowResponder::class)
            && $container->has(PageflowSharerContract::class)
        ) {
            /** @var PageflowResponder $responder */
            $responder = $container->make(PageflowResponder::class);
            /** @var PageflowSharerContract $sharer */
            $sharer = $container->make(PageflowSharerContract::class);
            $sharer->share($request, $responder);
        }

        return $next($request);
    }
}
