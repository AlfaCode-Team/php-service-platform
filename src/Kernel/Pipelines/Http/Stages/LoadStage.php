<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\{DependencyGraphCalculator, OnDemandLoader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class LoadStage implements HttpStageContract
{
    public function __construct(
        private readonly DependencyGraphCalculator $calculator,
        private readonly OnDemandLoader $loader,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $service = $request->attribute('target_service');
        $graph = $this->calculator->resolve($service);
        $container = $this->loader->load($graph, $request);

        return $next($request->withContainer($container));
    }
}
