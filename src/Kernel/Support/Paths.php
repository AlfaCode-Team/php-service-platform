<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Support;

/**
 * Paths — single source of truth for filesystem locations.
 *
 * Set ONCE at boot from bootstrap/app.php (or auto-detected). After boot it is
 * read-only, which makes it safe under OpenSwoole long-lived workers — no
 * per-request mutation, no cross-coroutine leakage.
 */
final class Paths
{
    private static ?string $base = null;
    private static ?string $project = null;

    /** Set the application base path. Call once, before Kernel::build(). */
    public static function setBase(string $path): void
    {
        self::$base = rtrim($path, '/');
    }

    /**
     * Set the active project path (e.g. /app/projects/admin). Call once, before
     * Kernel::build(). When set, per-project runtime state (var/, userdata/)
     * resolves under it instead of the workspace base, isolating each project's
     * manifests, logs, caches and tenant data.
     */
    public static function setProject(?string $path): void
    {
        self::$project = $path === null ? null : rtrim($path, '/');
    }

    public static function base(string $append = ''): string
    {
        if (self::$base === null) {
            // Auto-detect: walk up from this file until composer.json is found.
            self::$base = self::detectBase();
        }
        return $append === '' ? self::$base : self::$base . '/' . ltrim($append, '/');
    }

    /**
     * The active project root. Falls back to the workspace base when no project
     * has been set (e.g. base-only boot, or CLI/worker without a project).
     */
    public static function project(string $append = ''): string
    {
        $root = self::$project ?? self::base();
        return $append === '' ? $root : $root . '/' . ltrim($append, '/');
    }

    public static function storage(string $append = ''): string
    {
        return self::base('storage' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    /**
     * var/ — ephemeral, regenerable runtime state (compiled manifests, caches,
     * logs, locks, tmp). Per-project: lives under the active project root. Safe
     * to wipe on deploy; a fresh worker rebuilds it.
     */
    public static function var(string $append = ''): string
    {
        return self::project('var' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    /** var/logs/ — application log streams (errors, security, access, …). */
    public static function logs(string $append = ''): string
    {
        return self::var('logs' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    /** var/cache/ — compiled artefacts (manifests, config caches). */
    public static function cache(string $append = ''): string
    {
        return self::var('cache' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    /**
     * userdata/ — per-tenant persisted state (uploads, reports, exports).
     * Per-project: lives under the active project root. MUST be backed up and
     * survive deploys — never wiped automatically.
     */
    public static function userdata(string $append = ''): string
    {
        return self::project('userdata' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    /**
     * config/ — project-level configuration (migrations, environments, app config).
     * Per-project: lives under the active project root. Keeps database, app, and
     * environment configs scoped to the project.
     */
    public static function config(string $append = ''): string
    {
        return self::project('config' . ($append === '' ? '' : '/' . ltrim($append, '/')));
    }

    private static function detectBase(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return getcwd() ?: __DIR__;
    }
}
