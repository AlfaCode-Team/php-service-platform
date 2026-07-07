<?php

declare(strict_types=1);

/**
 * Cookie configuration — every value is driven by the environment so cookie
 * behaviour can be tuned per deployment without touching code.
 *
 * Resolution order at runtime:
 *   1. projects/<name>/config/cookie.php  (project override — copy this file there)
 *   2. plugins/Cookie/config/cookie.php   (this file — framework default)
 *
 * Read it anywhere with the cookie_config() helper:
 *   cookie_config('lifetime');   // 120
 *   cookie_config('same_site');  // 'Lax'
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Lifetime (minutes)
    |--------------------------------------------------------------------------
    | How long a queued cookie lives when no explicit lifetime is given.
    | 0 = session cookie (cleared when the browser closes).
    */
    'lifetime' => (int) env('COOKIE_LIFETIME', 120),

    /*
    |--------------------------------------------------------------------------
    | Path & Domain
    |--------------------------------------------------------------------------
    | The URL path the cookie is valid for, and the domain scope. A null
    | domain binds the cookie to the exact host that issued it.
    */
    'path'   => (string) env('COOKIE_PATH', '/'),
    'domain' => env('COOKIE_DOMAIN', null) ?: null,

    /*
    |--------------------------------------------------------------------------
    | Secure (HTTPS only)
    |--------------------------------------------------------------------------
    | When true the cookie is only sent over TLS. Keep ON in production; set
    | COOKIE_SECURE=false for local plain-http development.
    */
    'secure' => filter_var(env('COOKIE_SECURE', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | HttpOnly (no JavaScript access)
    |--------------------------------------------------------------------------
    | Hides the cookie from document.cookie — strong XSS defence. Disable only
    | for cookies a front-end script must read.
    */
    'http_only' => filter_var(env('COOKIE_HTTP_ONLY', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | SameSite
    |--------------------------------------------------------------------------
    | CSRF mitigation: 'Lax' | 'Strict' | 'None'. 'None' requires secure=true.
    */
    'same_site' => (string) env('COOKIE_SAME_SITE', 'Lax'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Exemptions
    |--------------------------------------------------------------------------
    | Cookie NAMES whose values are stored as PLAINTEXT (never encrypted on
    | write, never decrypted on read) even when an EncryptionPort is bound.
    |
    | Exempt a cookie when its raw value must stay stable and readable as-is:
    |   - a JS-readable flag (theme, locale) the front-end reads directly;
    |   - an opaque session/binding cookie that a pre-load security layer reads
    |     RAW (e.g. CsrfTokenLayer's bindCookie). Encryption rotates the
    |     ciphertext each response, which would break that binding — exempting
    |     it keeps the value byte-stable across requests.
    |
    | The final list is the base names below MERGED with the comma-separated
    | COOKIE_ENCRYPT_EXEMPT env var (so deployments can add more without code).
    */
    'encrypt_exempt' => array_values(array_unique(array_filter(array_map('trim', array_merge(
        [
            // 'hkm_session', // CSRF bindCookie — must stay raw/stable for the security stage
        ],
        explode(',', (string) (env('COOKIE_ENCRYPT_EXEMPT') ?: '')),
    ))))),

];
