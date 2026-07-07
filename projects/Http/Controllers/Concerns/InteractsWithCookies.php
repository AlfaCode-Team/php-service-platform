<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Cookie\Infrastructure\CookieJar;

/**
 * Cookie helpers for base controllers.
 *
 * Base controllers implement RequestAware, so ExecuteStage calls setRequest()
 * with the active Request (the one carrying the request-scoped container) BEFORE
 * the action runs. The cookie helpers therefore need no $request argument:
 *
 *   public function index(): Response   // RequestAware — no $request param
 *   {
 *       $this->queueCookie('theme', 'dark');
 *       return $this->view('welcome', ['theme' => $this->cookie('theme', 'light')]);
 *   }
 *
 * You may still pass an explicit $request to any helper to override the stored
 * one. Queued cookies are flushed (and encrypted when an EncryptionPort is
 * bound) automatically by QueuedCookiesStage; attribute defaults come from
 * config/cookie.php + your .env.
 */
trait InteractsWithCookies
{
    use HasRequest;

    /** The request-scoped CookieJar, or null if the Cookie plugin is not loaded. */
    protected function cookieJar(?Request $request = null): ?CookieJar
    {
        $container = $this->resolveRequest($request)->container();

        if ($container === null || !$container->has(CookieJar::class)) {
            return null;
        }

        $jar = $container->make(CookieJar::class);

        return $jar instanceof CookieJar ? $jar : null;
    }

    /**
     * Queue a cookie for the outgoing response (encrypted on flush unless exempt).
     * $minutes overrides the configured default lifetime; null keeps the default.
     */
    protected function queueCookie(string $name, string $value, ?int $minutes = null, ?Request $request = null): void
    {
        $jar = $this->cookieJar($request);
        if ($jar === null) {
            return;
        }

        $minutes === null
            ? $jar->queue($name, $value)
            : $jar->queue($name, $value, maxAge: $minutes * 60);
    }

    /** Queue a long-lived (~5 years) cookie. */
    protected function rememberCookie(string $name, string $value, ?Request $request = null): void
    {
        $this->cookieJar($request)?->forever($name, $value);
    }

    /**
     * Read an incoming cookie, transparently decrypting it. Returns $default when
     * the cookie is absent, tampered, or the Cookie plugin is unavailable.
     */
    protected function cookie(string $name, ?string $default = null, ?Request $request = null): ?string
    {
        $req = $this->resolveRequest($request);
        $jar = $this->cookieJar($req);

        return $jar === null
            ? ($req->cookie($name) ?? $default)
            : ($jar->read($req, $name) ?? $default);
    }

    /** Queue deletion of a cookie on the outgoing response. */
    protected function forgetCookie(string $name, ?Request $request = null): void
    {
        $this->cookieJar($request)?->forget($name);
    }

    /** Whether a cookie with this name is already queued for the response. */
    protected function hasQueuedCookie(string $name, ?Request $request = null): bool
    {
        return $this->cookieJar($request)?->hasQueued($name) ?? false;
    }

    /**
     * Decrypt a raw cookie value (the symmetric counterpart to the encryption
     * applied on flush). Returns the value unchanged when no EncryptionPort is
     * bound, or null when the value is tampered/undecryptable. Prefer cookie()
     * for incoming cookies — use this only for a value you already hold.
     */
    protected function decryptCookie(?string $value, ?Request $request = null): ?string
    {
        return $this->cookieJar($request)?->decrypt($value) ?? $value;
    }
}
