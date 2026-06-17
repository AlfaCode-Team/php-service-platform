<?php

declare(strict_types=1);

namespace Plugins\Session;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\Session\Infrastructure\Handlers\ArraySessionHandler;
use Plugins\Session\Infrastructure\Handlers\FileSessionHandler;
use Plugins\Session\Infrastructure\Http\StartSessionStage;
use Plugins\Session\Infrastructure\Store;

/**
 * Session plugin — provides the kernel SessionPort and drives its lifecycle.
 *
 * The Store is bound as a per-request singleton so StartSessionStage and any
 * module controller resolve the SAME instance for the request. Driver selection
 * (file | array) is env-driven; file uses the project var/ path via Paths.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'session.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SessionPort::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(SessionPort::class)) {
            return; // a project already provided SessionPort
        }

        $container->singleton(SessionPort::class, static function (): SessionPort {
            $lifetime = (int) (env('SESSION_LIFETIME') ?: 7200);
            $driver   = strtolower((string) (env('SESSION_DRIVER') ?: 'file'));

            $handler = $driver === 'array'
                ? new ArraySessionHandler($lifetime)
                : new FileSessionHandler(
                    path:     env('SESSION_PATH') ?: Paths::var('sessions'),
                    lifetime: $lifetime,
                );

            return new Store(
                name:          (string) (env('SESSION_COOKIE') ?: 'hkm_session'),
                handler:       $handler,
                serialization: (string) (env('SESSION_SERIALIZATION') ?: 'json'),
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Open/persist the session around the request, after the container exists.
        $http->hook('after.load', StartSessionStage::class, priority: 20);
    }
}
