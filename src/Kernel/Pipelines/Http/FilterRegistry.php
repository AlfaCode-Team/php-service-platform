<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * FilterRegistry — maps a SHORT route-filter alias to a pipeline stage class.
 *
 * Route filters let a route declare cross-cutting behaviour DECLARATIVELY in
 * module.json / proj.json instead of every stage self-gating on a path pattern:
 *
 *   { "method": "GET", "path": "/admin", "handler": "...@index",
 *     "filters": ["auth", "throttle:60,1"] }
 *
 * Plugins publish the aliases they provide in Provider::boot():
 *
 *   $http->filter('auth',     RequireAuthStage::class);
 *   $http->filter('throttle', ApiRateLimitStage::class);
 *
 * The alias map is global and stateless (built once at module boot). Stages are
 * resolved per request from the CoreContainer, exactly like hook stages, so they
 * remain OpenSwoole-safe (no per-request state on the registry).
 */
final class FilterRegistry
{
    /** @var array<string, class-string<HttpStageContract>> */
    private array $aliases = [];

    /**
     * @param class-string<HttpStageContract> $stageClass
     */
    public function register(string $alias, string $stageClass): void
    {
        if (isset($this->aliases[$alias]) && $this->aliases[$alias] !== $stageClass) {
            throw new \InvalidArgumentException(
                "Route filter alias [{$alias}] is already bound to [{$this->aliases[$alias]}]."
            );
        }
        $this->aliases[$alias] = $stageClass;
    }

    public function has(string $alias): bool
    {
        return isset($this->aliases[$alias]);
    }

    /**
     * Resolve an alias to a stage instance (from the core container when bound,
     * otherwise a plain no-arg construction — mirrors HttpPipeline::resolveHook).
     */
    public function resolve(string $alias, CoreContainer $core): HttpStageContract
    {
        if (!isset($this->aliases[$alias])) {
            throw new \InvalidArgumentException(
                "Unknown route filter alias [{$alias}]. Register it in a Provider::boot() via \$http->filter()."
            );
        }

        $class = $this->aliases[$alias];

        return $core->has($class) ? $core->make($class) : new $class();
    }
}
