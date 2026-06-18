<?php

declare(strict_types=1);

namespace Plugins\Task\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Example plugin-owned route filter.
 *
 * Rejects requests that do not accept a JSON response with 406, before the
 * controller runs. A route opts in declaratively:
 *
 *   { "method": "POST", "path": "/api/tasks",
 *     "handler": "...@create", "filters": ["json"] }
 *
 * Registered as the "json" alias from THIS plugin's Provider::boot() — any
 * plugin can publish its own filter aliases the same way.
 */
final class RequireJsonStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        if (!$request->expectsJson()) {
            return Response::json([
                'error' => [
                    'code'    => 'not_acceptable',
                    'message' => 'This endpoint only returns JSON. Send Accept: application/json.',
                ],
            ], 406);
        }

        return $next($request);
    }
}
