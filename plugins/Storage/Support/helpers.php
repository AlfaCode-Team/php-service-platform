<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

if (!function_exists('storage_config')) {
    /**
     * Read the storage configuration (config/storage.php), env-driven and cached
     * per process. A project copy at projects/<name>/config/storage.php wins over
     * the plugin default.
     *
     *   storage_config();              // full array
     *   storage_config('driver');      // 'local' | 's3'
     *   storage_config('s3.bucket');   // dotted access into a section
     *   storage_config('local.root', '/tmp')   // value, or fallback if absent
     *
     * @return mixed the whole config array, or a single (dotted) key's value
     */
    function storage_config(?string $key = null, mixed $default = null): mixed
    {
        /** @var array<string, mixed>|null $config */
        static $config = null;

        if ($config === null) {
            $projectFile = Paths::config('storage.php');
            $pluginFile  = __DIR__ . '/../config/storage.php';

            $file   = is_file($projectFile) ? $projectFile : $pluginFile;
            $loaded = require $file;
            $config = is_array($loaded) ? $loaded : [];
        }

        if ($key === null) {
            return $config;
        }

        // Dotted access: "s3.bucket" → $config['s3']['bucket'].
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
