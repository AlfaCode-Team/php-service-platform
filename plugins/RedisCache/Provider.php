<?php

declare(strict_types=1);

namespace Plugins\RedisCache;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\RedisCache\Infrastructure\RedisCacheAdapter;
use Plugins\RedisCache\Infrastructure\RedisConnection;
use Plugins\RedisCache\Infrastructure\RedisQueueAdapter;

/**
 * RedisCache plugin — Redis adapters for the kernel CachePort and QueuePort.
 *
 * Only binds when REDIS_HOST is set AND ext-redis is loaded; otherwise the base
 * bootstrap's in-memory CachePort stays in place (graceful fallback, no hard
 * dependency on Redis to boot). One RedisConnection is shared by both adapters.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'cache.redis';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [CachePort::class, QueuePort::class];
    }

    public function register(ModuleContainer $container): void
    {
        $host = env('REDIS_HOST') ?: '';
        if ($host === '' || !\extension_loaded('redis')) {
            return; // not configured / extension missing — keep the in-memory fallback
        }

        // One lazy connection shared by both adapters (it does not actually
        // connect until first use), so a request that touches cache and queue
        // opens a single Redis socket, not two.
        $connection = new RedisConnection(
            host:     $host,
            port:     (int) (env('REDIS_PORT') ?: 6379),
            password: env('REDIS_PASSWORD') ?: null,
            database: (int) (env('REDIS_DB') ?: 0),
            prefix:   env('REDIS_PREFIX') ?: '',
            persistent: filter_var(env('REDIS_PERSISTENT') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        );

        if (!$container->has(CachePort::class) || $this->shouldOverride()) {
            $container->bind(CachePort::class, static fn () => new RedisCacheAdapter($connection));
        }
        if (!$container->has(QueuePort::class) || $this->shouldOverride()) {
            $container->bind(QueuePort::class, static fn () => new RedisQueueAdapter($connection));
        }
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }

    /**
     * The base bootstrap binds an in-memory CachePort by default; when Redis is
     * explicitly configured the operator wants it to win, so override unless
     * REDIS_OVERRIDE=false is set.
     */
    private function shouldOverride(): bool
    {
        return filter_var(env('REDIS_OVERRIDE') ?: 'true', FILTER_VALIDATE_BOOLEAN);
    }
}
