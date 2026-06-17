<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
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
        $response = $controller->$method($request, ...$params);

        return $response->withHeader('X-Correlation-ID', $request->attribute('correlation_id', ''));
    }
}
