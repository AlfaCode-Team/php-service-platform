<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class ExecuteStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $entry = $request->attribute('route_entry');
        $container = $request->container();
        $scope = $entry['solves'] ?? '';

        [$controllerClass, $method] = explode('@', $entry['handler']);

        $controller = $container->makeInScope($controllerClass, $scope);

        $params = array_values($request->attribute('route_params', []));

        // A RequestAware controller holds the Request itself (set here with the
        // same copy the action would receive — the one carrying the request-scoped
        // container), so its actions take ONLY route params, not $request.
        // Other controllers keep the conventional ($request, ...$params) signature.
        if ($controller instanceof RequestAware) {
            $controller->setRequest($request);
            $response = $controller->$method(...$params);
        } else {
            $response = $controller->$method($request, ...$params);
        }

        return $response->withHeader('X-Correlation-ID', $request->attribute('correlation_id', ''));
    }
}
