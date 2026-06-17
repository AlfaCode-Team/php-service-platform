<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\ApiRateLimitStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\CorsStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\HmacSignedStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\RequireAuthStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\SecureHeadersStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\ShieldStage;

/**
 * SecurityFilters plugin — the 0.3 HTTP filters rebuilt as GDA pipeline stages.
 *
 * Routing decides when each stage enforces: every stage inspects the resolved
 * request path/method and only acts on its configured scope, otherwise it calls
 * $next untouched. Priorities follow the kernel convention (10-19 security).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'http.security_filters';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
        // Stages are constructed by the pipeline (no-arg) and resolve any
        // services from the request-scoped container at handle time.
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // CORS first: answers preflight (OPTIONS) before auth so it is never rejected.
        $http->hook('after.security', CorsStage::class, priority: 10);
        // HMAC signing + auth/authorization run before modules load (cheap rejects).
        $http->hook('after.security', HmacSignedStage::class, priority: 11);
        // Require authentication on specific paths (AUTH_PROTECTED_PATHS).
        $http->hook('after.security', RequireAuthStage::class, priority: 13);
        $http->hook('after.security', ShieldStage::class, priority: 15);

        // Rate limiter runs after load so it can resolve CachePort from the
        // request-scoped ModuleContainer.
        $http->hook('after.load', ApiRateLimitStage::class, priority: 12);

        // Security response headers decorate the outgoing response last.
        $http->hook('after.execute', SecureHeadersStage::class, priority: 90);
    }
}
