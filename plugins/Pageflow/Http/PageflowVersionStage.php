<?php

declare(strict_types=1);

namespace Plugins\Pageflow\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Asset-version guard for the Pageflow protocol.
 *
 * When a Pageflow XHR GET carries a stale X-Pageflow-Version (the client booted
 * against older assets), respond 409 with X-Pageflow-Location so the client does
 * a full reload and picks up the new bundle. Non-pageflow requests pass through.
 *
 * The current version comes from PAGEFLOW_VERSION (e.g. a build hash).
 */
final class PageflowVersionStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $isPageflow = strtolower((string) ($request->header('X-Pageflow') ?? '')) === 'true';

        if ($isPageflow && strtoupper($request->method()) === 'GET') {
            $clientVersion = (string) ($request->header('X-Pageflow-Version') ?? '');
            $currentVersion = (string) (env('PAGEFLOW_VERSION') ?: '');

            if ($currentVersion !== '' && $clientVersion !== $currentVersion) {
                return Response::json([], 409, [
                    'X-Pageflow-Location' => $this->fullUrl($request),
                ]);
            }
        }

        return $next($request);
    }

    private function fullUrl(Request $request): string
    {
        $path  = $request->path();
        $query = http_build_query($request->queryAll());
        return $query === '' ? $path : $path . '?' . $query;
    }
}
