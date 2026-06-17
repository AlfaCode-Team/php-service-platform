<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Socialite\Http;

/**
 * Minimal session store used by the OAuth flow to persist the CSRF `state`
 * and PKCE `code_verifier` between the redirect and callback legs.
 *
 * The GDA kernel is stateless by design, so this plugin owns its own thin
 * session wrapper over native PHP sessions. A project may swap it for a
 * cache-backed implementation by binding a different instance.
 */
class Session
{
    public function __construct()
    {
        if (\PHP_SAPI !== 'cli' && session_status() === \PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }
}
