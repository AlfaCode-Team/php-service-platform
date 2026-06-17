<?php
declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Global path helpers.
 *
 * Registered via composer.json "autoload.files" so they are always available.
 * Thin wrappers over the Paths registry — no per-request state.
 */

if (!function_exists('base_path')) {
    function base_path(string $append = ''): string
    {
        return Paths::base($append);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        return Paths::storage($append);
    }
}

if (!function_exists('var_path')) {
    function var_path(string $append = ''): string
    {
        return Paths::var($append);
    }
}

if (!function_exists('logs_path')) {
    function logs_path(string $append = ''): string
    {
        return Paths::logs($append);
    }
}

if (!function_exists('cache_path')) {
    function cache_path(string $append = ''): string
    {
        return Paths::cache($append);
    }
}

if (!function_exists('userdata_path')) {
    function userdata_path(string $append = ''): string
    {
        return Paths::userdata($append);
    }
}

if (!function_exists('config_path')) {
    function config_path(string $append = ''): string
    {
        return Paths::config($append);
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment value.
     *
     * Canonical accessor for values loaded by {@see \App\Bootstrap\Environment\LoadEnvironment}.
     * Reads from $_ENV / $_SERVER first (where the loader injects values) and falls
     * back to getenv() for genuine OS/process variables. This lets the loader skip
     * the expensive, coroutine-unsafe putenv() call entirely — getenv() is no longer
     * the source of truth, so first-party code must read config through env().
     *
     * Strict drop-in for `getenv($k) ?: $d` — returns the raw string or $default;
     * no boolean/null coercion, to preserve existing call-site semantics.
     *
     * @param string $key
     * @param mixed  $default Returned when the key is absent.
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null) ? $default : $value;
    }
}
