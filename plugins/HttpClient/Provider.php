<?php

declare(strict_types=1);

namespace Plugins\HttpClient;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\HttpClient\Infrastructure\CurlHttpClient;

/**
 * HttpClient plugin — cURL adapter for the kernel HttpClientPort.
 *
 * Always available (cURL is part of every standard PHP build), so unlike the
 * Mail/Storage adapters it binds unconditionally — but still yields to a
 * project that wired its own HttpClientPort in withPorts().
 *
 * ACTIVATION: this is an ON-DEMAND module. A Gateway that makes outbound calls
 * must declare it in module.json so it joins the request graph:
 *   { "requires": ["http.client"] }
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'http.client';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [HttpClientPort::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(HttpClientPort::class)) {
            return; // a project already provided HttpClientPort
        }

        $container->bind(HttpClientPort::class, static fn() => new CurlHttpClient(
            defaultTimeout:        (int) (env('HTTP_CLIENT_TIMEOUT') ?: 30),
            defaultConnectTimeout: (int) (env('HTTP_CLIENT_CONNECT_TIMEOUT') ?: 10),
            defaultRetry:          (int) (env('HTTP_CLIENT_RETRY') ?: 0),
        ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
