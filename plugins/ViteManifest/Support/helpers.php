<?php

declare(strict_types=1);

/**
 * ViteManifest global helpers — the ergonomic surface for (isolated-scope)
 * views, which have no container. Each builds/uses a process-shared Vite from
 * env (config is deploy-time static, so caching per-surface is Swoole-safe).
 *
 * Loaded when the plugin is enabled (the `[psp-support:ViteManifest]` require in
 * app/bootstrap/app.php, managed by `hkm plugins enable vitemanifest`).
 */

use Plugins\ViteManifest\ViteFactory;
use Plugins\ViteManifest\Infrastructure\Vite;
use Plugins\ViteManifest\API\Contracts\ViteContract;

if (!function_exists('vite')) {
    /**
     * Render Vite tags for one or more entry points.
     *
     *   <?= vite('src/surfaces/admin/index.tsx', 'admin') ?>
     *
     * @param string|list<string> $entrypoints
     */
    function vite(string|array $entrypoints, ?string $surface = null): Stringable
    {
        return vite_instance($surface)->render($entrypoints);
    }
}

if (!function_exists('vite_asset')) {
    /** Resolve a single asset URL (hashed in prod, dev URL under HMR). */
    function vite_asset(string $asset, ?string $surface = null): string
    {
        return vite_instance($surface)->asset($asset);
    }
}

if (!function_exists('vite_react_refresh')) {
    /** The React Fast Refresh preamble (dev only; empty in prod). */
    function vite_react_refresh(?string $surface = null): Stringable
    {
        return vite_instance($surface)->reactRefresh();
    }
}

if (!function_exists('vite_is_hot')) {
    /** True when the Vite dev server is running for the surface. */
    function vite_is_hot(?string $surface = null): bool
    {
        return vite_instance($surface)->isRunningHot();
    }
}

if (!function_exists('vite_instance')) {
    /**
     * The shared Vite resolver for a surface. Cached per-surface: config is
     * process-static, so this never leaks request state under OpenSwoole.
     */
    function vite_instance(?string $surface = null): ViteContract
    {
        /** @var array<string, Vite> $cache */
        static $cache = [];
        $key = $surface ?? '__default__';

        if (!isset($cache['__base__'])) {
            $cache['__base__'] = ViteFactory::fromEnv();
        }
        if (!isset($cache[$key])) {
            $cache[$key] = $surface === null
                ? $cache['__base__']
                : $cache['__base__']->forSurface($surface);
        }
        return $cache[$key];
    }
}
