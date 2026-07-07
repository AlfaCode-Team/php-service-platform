<?php

declare(strict_types=1);

namespace Plugins\ViteManifest;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\ViteManifest\API\Contracts\ViteContract;

/**
 * ViteManifest plugin — resolves Vite build assets (hashed <script>/<link> tags,
 * HMR dev-server injection, preloading, React refresh) for HTML routes.
 *
 * The GDA-clean successor to the old Laravel `Vite` transplant: no framework
 * globals, config injected from env, surfaces (`manifest-<surface>.json` +
 * `<surface>-hot`) matching the `hkmPlugin` vite output.
 *
 * ACTIVATION: on-demand. A route that renders a shell needing built assets
 * declares it:  { "requires": ["vite.manifest"] }
 *
 * Inside a view use the helpers (Support/helpers.php, wired by `hkm plugins
 * enable`): `vite('src/surfaces/admin/index.tsx', 'admin')`, `vite_asset(...)`,
 * `vite_react_refresh('admin')`. In a controller inject {@see ViteContract}.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'vite.manifest';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [ViteContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(ViteContract::class)) {
            return; // a project supplied its own resolver
        }

        // Stateless config (paths are deploy-time) → a per-request singleton is
        // cheap and OpenSwoole-safe; the manifest cache lives in ManifestReader.
        $container->singleton(ViteContract::class, static fn(): ViteContract => ViteFactory::fromEnv());
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Nothing to hook — asset resolution is pull-based from views/controllers.
    }
}
