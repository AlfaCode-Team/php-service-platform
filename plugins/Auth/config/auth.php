<?php

declare(strict_types=1);

/**
 * Auth configuration — named guards + user providers managed by AuthManager.
 *
 * Resolution order at runtime:
 *   1. projects/<name>/config/auth.php  (project override — copy this file there)
 *   2. plugins/Auth/config/auth.php      (this file — framework default)
 *
 * Read it with the auth_config() helper:
 *   auth_config();                 // full array
 *   auth_config('defaults.guard'); // 'web'
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | The guard + provider used when AuthManager::guard()/provider() is called
    | with no explicit name.
    */
    'defaults' => [
        'guard'    => env('AUTH_GUARD', 'web'),
        'provider' => env('AUTH_PROVIDER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    | Each guard binds a driver (session/jwt/token/request — filesystem-scanned
    | from Infrastructure/Auth/Drivers) to a named provider below.
    |   - web:     stateful browser session (+ remember-me via SessionAuthStage)
    |   - api:     personal access tokens (Bearer <id>.<secret>)
    |   - jwt:     stateless Bearer JWTs
    |   - request: credential-agnostic — whatever the SecurityGateway attached
    */
    'guards' => [
        'web'     => ['driver' => 'session', 'provider' => 'users'],
        'api'     => ['driver' => 'token',   'provider' => 'users'],
        'jwt'     => ['driver' => 'jwt',     'provider' => 'users'],
        'request' => ['driver' => 'request', 'provider' => 'users'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    | Named user sources. 'model' = ModelUserProvider over UserServiceContract
    | (the central identity store). Add more to back a guard with another store.
    */
    'providers' => [
        'users' => ['driver' => 'model'],
    ],
];
