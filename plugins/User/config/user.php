<?php

declare(strict_types=1);

/**
 * User plugin configuration.
 *
 * Published to the project's config/user.php when the plugin is enabled
 * (hkm plugins enable User). Read values through your config loader /
 * the env() helper. Keep secrets in .env, not here.
 */
return [
    'enabled' => true,

    // Add user settings here.
    // 'cache_ttl' => (int) (env('USER_CACHE_TTL') ?: 3600),
];
