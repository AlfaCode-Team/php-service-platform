<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;

/**
 * Session helpers for base controllers.
 *
 * Like InteractsWithCookies, base controllers implement RequestAware, so
 * ExecuteStage calls setRequest() with the active (container-bearing) Request
 * before the action runs. These helpers therefore need no $request argument:
 *
 *   public function login(): Response   // RequestAware — no $request param
 *   {
 *       $this->session()->regenerate();          // fixation defence after auth
 *       $this->put('user_id', $id);
 *       $this->flash('status', 'Welcome back!');
 *       return $this->redirect('/dashboard');
 *   }
 *
 * The session is resolved from the request-scoped container (the Session plugin
 * binds SessionPort as a per-request singleton). The Session plugin is
 * "essential" — loaded on every request — and StartSessionStage opens/persists
 * the session around the action, so reads/writes here Just Work. When the plugin
 * is absent, the read helpers fall back to their defaults and the write helpers
 * are no-ops (so a controller stays usable without sessions wired).
 *
 * You may still pass an explicit $request to any helper to override the stored one.
 */
trait InteractsWithSession
{
    use HasRequest;

    /** The request-scoped SessionPort, or null if the Session plugin is not loaded. */
    protected function session(?Request $request = null): ?SessionPort
    {
        $container = $this->resolveRequest($request)->container();

        if ($container === null || !$container->has(SessionPort::class)) {
            return null;
        }

        $session = $container->make(SessionPort::class);

        return $session instanceof SessionPort ? $session : null;
    }

    /** Read a session value, or $default when absent / the plugin is unavailable. */
    protected function sessionGet(string $key, mixed $default = null, ?Request $request = null): mixed
    {
        return $this->session($request)?->get($key, $default) ?? $default;
    }

    /** Whether the session has a (non-removed) value under $key. */
    protected function sessionHas(string $key, ?Request $request = null): bool
    {
        return $this->session($request)?->has($key) ?? false;
    }

    /** Write a session value (no-op when the Session plugin is not loaded). */
    protected function sessionPut(string $key, mixed $value, ?Request $request = null): void
    {
        $this->session($request)?->put($key, $value);
    }

    /** Read and remove a session value in one call. */
    protected function sessionPull(string $key, mixed $default = null, ?Request $request = null): mixed
    {
        return $this->session($request)?->pull($key, $default) ?? $default;
    }

    /** Remove a session value. */
    protected function sessionForget(string $key, ?Request $request = null): void
    {
        $this->session($request)?->forget($key);
    }

    /**
     * Flash a value for exactly the next request (put-now-read-next pattern) —
     * the classic home for one-shot status messages after a redirect.
     */
    protected function flash(string $key, mixed $value, ?Request $request = null): void
    {
        $this->session($request)?->flash($key, $value);
    }

    /** The CSRF token for the current session (empty string when unavailable). */
    protected function csrfToken(?Request $request = null): string
    {
        return $this->session($request)?->token() ?? '';
    }

    /**
     * Rotate the session id while keeping its data — call right after a successful
     * login to defend against session fixation.
     */
    protected function regenerateSession(?Request $request = null): void
    {
        $this->session($request)?->regenerate();
    }

    /** New session id AND wipe all data — the logout primitive. */
    protected function invalidateSession(?Request $request = null): void
    {
        $this->session($request)?->invalidate();
    }
}
