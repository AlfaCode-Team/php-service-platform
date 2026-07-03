<?php

declare(strict_types=1);

namespace Plugins\Pageflow;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\Pageflow\Http\PageflowShareStage;
use Plugins\Pageflow\Http\PageflowVersionStage;
use Plugins\Pageflow\Http\RegistryPageflowSharer;

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
        // Per-request singleton so share()/mergeShared() and render() see one bag.
        $container->singleton(PageflowResponder::class, static function () {
            return new PageflowResponder(
                version:    env('PAGEFLOW_VERSION') ?: '1',
                layoutPath: env('PAGEFLOW_ROOT_VIEW') ?: '',
                appId:      env('PAGEFLOW_APP_ID') ?: 'app',
            );
        });

        // Default sharer: runs every contributor registered via pageflow_share().
        // Bind your own PageflowSharerContract in the project to override.
        $container->bind(PageflowSharerContract::class, static fn() => new RegistryPageflowSharer());
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Stale-asset guard runs before modules load (cheap 409 reject).
        $http->hook('after.security', PageflowVersionStage::class, priority: 18);

        // Populate shared props once modules are loaded (do_action('pageflow_share')).
        $http->hook('after.load', PageflowShareStage::class, priority: 45);
    }
}
