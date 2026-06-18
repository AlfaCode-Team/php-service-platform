<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

if (!function_exists('cookie_config')) {
    /**
     * Read the cookie configuration (config/cookie.php), env-driven and cached
     * per process. A project copy at projects/<name>/config/cookie.php wins over
     * the plugin default.
     *
     *   cookie_config();              // full array
     *   cookie_config('same_site');   // 'Lax'
     *   cookie_config('lifetime', 60) // value, or 60 if the key is absent
     *
     * @return mixed the whole config array, or a single key's value
     */
    function cookie_config(?string $key = null, mixed $default = null): mixed
    {
        /** @var array<string, mixed>|null $config */
        static $config = null;

        if ($config === null) {
            $projectFile = Paths::config('cookie.php');
            $pluginFile  = __DIR__ . '/../config/cookie.php';

            $file   = is_file($projectFile) ? $projectFile : $pluginFile;
            $loaded = require $file;
            $config = is_array($loaded) ? $loaded : [];
        }

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }
}

if (!function_exists('cookie')) {
    /**
     * Build a cookie attribute set from config defaults — a ready-to-spread
     * array for CookieJar::queue() or Response::withCookie().
     *
     *   // Queue via the request-scoped jar (auto-encrypted + flushed):
     *   $jar->queue(...cookie('theme', 'dark', minutes: 60));
     *
     *   // Or apply straight to a response:
     *   return Response::json($data)->withCookie(...cookie('seen', '1'));
     *
     * Pass $minutes to override the configured lifetime (config is in minutes;
     * the returned `maxAge` is in seconds, as both consumers expect). Any other
     * attribute can be overridden through $overrides (path/domain/secure/...).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    function cookie(
        string $name,
        string $value = '',
        ?int $minutes = null,
        array $overrides = [],
    ): array {
        $minutes = $minutes ?? (int) cookie_config('lifetime', 0);

        return array_merge([
            'name'     => $name,
            'value'    => $value,
            'maxAge'   => $minutes * 60,
            'path'     => (string) cookie_config('path', '/'),
            'domain'   => cookie_config('domain', null),
            'secure'   => (bool) cookie_config('secure', true),
            'httpOnly' => (bool) cookie_config('http_only', true),
            'sameSite' => (string) cookie_config('same_site', 'Lax'),
        ], $overrides);
    }
}
