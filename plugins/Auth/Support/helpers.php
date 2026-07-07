<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

if (!function_exists('auth_config')) {
    /**
     * Read the auth configuration (config/auth.php), cached per process. A
     * project copy at projects/<name>/config/auth.php wins over the plugin
     * default. Supports dotted key access.
     *
     *   auth_config();                  // full array
     *   auth_config('defaults.guard');  // 'web'
     *   auth_config('guards.api');      // ['driver' => 'token', 'provider' => 'users']
     *
     * @return mixed the whole config array, or a single (dotted) key's value
     */
    function auth_config(?string $key = null, mixed $default = null): mixed
    {
        /** @var array<string, mixed>|null $config */
        static $config = null;

        if ($config === null) {
            $projectFile = Paths::config('auth.php');
            $pluginFile  = __DIR__ . '/../config/auth.php';

            $file   = is_file($projectFile) ? $projectFile : $pluginFile;
            $loaded = require $file;
            $config = is_array($loaded) ? $loaded : [];
        }

        if ($key === null) {
            return $config;
        }

        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
