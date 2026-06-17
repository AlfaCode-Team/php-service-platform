<?php

declare(strict_types=1);

namespace Plugins\Pageflow;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\Pageflow\Http\PageflowVersionStage;

/**
 * Pageflow plugin — server side of the Inertia-style SPA bridge.
 *
 * Controllers depend on PageflowResponder to return a component + props; the
 * responder negotiates JSON (XHR navigation) vs an HTML shell (initial load).
 * The version stage forces a hard reload when client assets are stale.
 *
 * The matching React client lives in the top-level frontend/ workspace.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'http.pageflow';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [PageflowResponder::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(PageflowResponder::class, static function () {
            $rootView = '';
            $viewPath = env('PAGEFLOW_ROOT_VIEW') ?: '';
            if ($viewPath !== '' && is_file($viewPath)) {
                $rootView = (string) file_get_contents($viewPath);
            }

            return new PageflowResponder(
                version:  env('PAGEFLOW_VERSION') ?: '1',
                rootView: $rootView,
                appId:    env('PAGEFLOW_APP_ID') ?: 'app',
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Stale-asset guard runs before modules load (cheap 409 reject).
        $http->hook('after.security', PageflowVersionStage::class, priority: 18);
    }
}
