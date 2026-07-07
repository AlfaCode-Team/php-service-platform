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

        // A route may declare its OWN requires[] (compiled into the route entry)
        // to pull specific plugins into THIS request's graph. This is how a
        // project route — whose '__project__' scope carries no requires — opts
        // into, say, view.rendering for one page without loading it app-wide.
        $entry    = $request->attribute('route_entry');
        $extra    = is_array($entry) && is_array($entry['requires'] ?? null) ? $entry['requires'] : [];

        $graph = $this->calculator->resolve($service, $extra);
        $container = $this->loader->load($graph, $request);

        return $next($request->withContainer($container));
    }
}
