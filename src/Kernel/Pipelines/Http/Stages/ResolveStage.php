<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher;

final class ResolveStage implements HttpStageContract
{
    public function __construct(
        private readonly RouteMatcher $matcher
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $match = $this->matcher->match($request->method(), $request->path());

        if ($match === null) {
            return Response::notFound();
        }

        $request = $request
            ->withAttribute('route_entry', $match['entry'])
            ->withAttribute('route_params', $match['params'])
            ->withAttribute('target_service', $match['entry']['solves']);

        return $next($request);
    }
}
