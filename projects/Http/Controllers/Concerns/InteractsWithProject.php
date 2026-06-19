<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Project\Bootstrap\Domain\DomainContext;
use Project\Bootstrap\Domain\DomainType;

/**
 * Project/domain helpers for base controllers.
 *
 * The DomainResolver attaches a DomainContext to the Request via
 * Request::withAttribute('domain', $ctx) — it is NEVER bound into a container
 * (OpenSwoole/coroutine-safe by construction), so the only way to reach it is
 * through the request. Base controllers implement RequestAware, so ExecuteStage
 * calls setRequest() with the active Request before the action runs; these
 * helpers therefore need no $request argument:
 *
 *   public function index(): Response   // RequestAware — no $request param
 *   {
 *       if ($this->isApi()) {
 *           return $this->ok(['project' => $this->projectName()]);
 *       }
 *       return $this->view('home', ['features' => $this->projectFeatures()]);
 *   }
 *
 * Every read helper degrades gracefully when no DomainContext is present (CLI /
 * worker, or an HTTP entry that never resolved a host): accessors return null,
 * the is*() probes return false, and feature lookups return their defaults — so
 * a controller stays usable without domain resolution wired. Use
 * requireProject() when an endpoint genuinely cannot proceed without one.
 *
 * You may still pass an explicit $request to any helper to override the stored one.
 */
trait InteractsWithProject
{
    use HasRequest;

    /** The resolved DomainContext for this request, or null when none was attached. */
    protected function project(?Request $request = null): ?DomainContext
    {
        $context = $this->resolveRequest($request)->attribute('domain');

        return $context instanceof DomainContext ? $context : null;
    }

    /** The DomainContext, or throw when the request carries none. */
    protected function requireProject(?Request $request = null): DomainContext
    {
        return $this->project($request)
            ?? throw new KernelException(
                'No DomainContext on the request — host resolution did not run for this entry point.',
                layer: 'controller.project',
            );
    }

    /** Registered project name (e.g. "shop"), or null when unresolved. */
    protected function projectName(?Request $request = null): ?string
    {
        return $this->project($request)?->name;
    }

    /** Absolute path to the active project directory, or null when unresolved. */
    protected function projectPath(?Request $request = null): ?string
    {
        return $this->project($request)?->projectPath;
    }

    /** The resolved face (admin / api / project / public), or null when unresolved. */
    protected function projectFace(?Request $request = null): ?DomainType
    {
        return $this->project($request)?->type;
    }

    /** The clean hostname that triggered this context, or null when unresolved. */
    protected function projectHost(?Request $request = null): ?string
    {
        return $this->project($request)?->host;
    }

    /** True when the request resolved to the admin face. */
    protected function isAdmin(?Request $request = null): bool
    {
        return $this->project($request)?->isAdmin() ?? false;
    }

    /** True when the request resolved to the api face. */
    protected function isApi(?Request $request = null): bool
    {
        return $this->project($request)?->isApi() ?? false;
    }

    /** True when the request resolved to the project face. */
    protected function isProject(?Request $request = null): bool
    {
        return $this->project($request)?->isProject() ?? false;
    }

    /** True when the request resolved to the public face. */
    protected function isPublic(?Request $request = null): bool
    {
        return $this->project($request)?->isPublic() ?? false;
    }

    /** True when an admin/api subdomain matched but no concrete project did. */
    protected function isPlatformOnly(?Request $request = null): bool
    {
        return $this->project($request)?->isPlatformOnly() ?? false;
    }

    /**
     * The project's feature list from proj.json "features" (string ids and/or
     * descriptor arrays). Empty when unresolved or none are declared.
     *
     * @return list<string|array<string, mixed>>
     */
    protected function projectFeatures(?Request $request = null): array
    {
        return $this->project($request)?->features ?? [];
    }

    /**
     * Whether $id is enabled — matches a plain string entry, or a descriptor
     * array whose "name"/"id" equals $id (unless it carries "enabled": false).
     */
    protected function hasFeature(string $id, ?Request $request = null): bool
    {
        foreach ($this->projectFeatures($request) as $feature) {
            if (is_string($feature) && $feature === $id) {
                return true;
            }
            if (is_array($feature)
                && (($feature['name'] ?? $feature['id'] ?? null) === $id)
                && ($feature['enabled'] ?? true) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The descriptor array for a feature declared as an object, or $default when
     * the feature is absent or was declared as a bare string id (no config).
     *
     * @return array<string, mixed>|mixed
     */
    protected function feature(string $id, mixed $default = null, ?Request $request = null): mixed
    {
        foreach ($this->projectFeatures($request) as $feature) {
            if (is_array($feature) && (($feature['name'] ?? $feature['id'] ?? null) === $id)) {
                return $feature;
            }
        }

        return $default;
    }
}
