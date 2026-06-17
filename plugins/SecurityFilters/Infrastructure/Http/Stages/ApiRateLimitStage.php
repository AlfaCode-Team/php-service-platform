<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Sliding-window rate limiter for API routes (GDA rewrite of ApiRateLimit).
 *
 * Counters live in CachePort with TTL auto-expiry. Identity prefers the
 * authenticated user id (from the kernel Identity), falling back to client IP.
 * Only enforces on paths under RATE_LIMIT_PREFIX so the router decides scope.
 *
 * Registered at the `after.load` slot: hooked stages are built with `new` from
 * the CoreContainer, so the CachePort is resolved from the request-scoped
 * ModuleContainer (available once LoadStage has run) instead of constructor DI.
 */
final class ApiRateLimitStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $prefix = (string) (env('RATE_LIMIT_PREFIX') ?: '/api');
        if (!str_starts_with($request->path(), $prefix)) {
            return $next($request);
        }

        $cache = $this->resolveCache($request);
        if ($cache === null) {
            // No cache available — fail open rather than block traffic.
            return $next($request);
        }

        $max    = max(1, (int) (env('RATE_LIMIT_MAX') ?: 300));
        $window = max(1, (int) (env('RATE_LIMIT_WINDOW') ?: 60));

        $key     = 'rl_' . hash('sha256', $this->resolveIdentity($request));
        $current = (int) ($cache->get($key) ?? 0);

        if ($current >= $max) {
            return Response::json([
                'error' => [
                    'code'        => 'rate_limit_exceeded',
                    'message'     => 'Too many requests. Please slow down and try again.',
                    'retry_after' => $window,
                ],
            ], 429, [
                'Retry-After'           => (string) $window,
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        if ($current === 0) {
            $cache->set($key, 1, $window);
        } else {
            $cache->increment($key);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $max - $current - 1));
    }

    private function resolveCache(Request $request): ?CachePort
    {
        $container = $request->container();
        if ($container === null || !$container->has(CachePort::class)) {
            return null;
        }
        $cache = $container->make(CachePort::class);
        return $cache instanceof CachePort ? $cache : null;
    }

    private function resolveIdentity(Request $request): string
    {
        $identity = $request->identity();
        if ($identity !== null && !$identity->isGuest()) {
            return 'user_' . $identity->userId;
        }
        $ip = $request->header('X-Forwarded-For') ?? $request->attribute('client_ip');
        return 'ip_' . ($ip ?: 'unknown');
    }
}
