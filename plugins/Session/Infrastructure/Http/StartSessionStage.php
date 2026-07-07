<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Session\Infrastructure\Handlers\Contracts\CookieBackedHandler;
use Plugins\Session\Infrastructure\Store;

/**
 * StartSessionStage — opens the session before modules run and persists it after
 * (GDA rewrite of the 0.3 StartSession filter).
 *
 * Registered at `after.load` so the request-scoped ModuleContainer (and the
 * SessionPort the Session provider bound into it) is available. It:
 *   1. loads the session keyed by the incoming session cookie,
 *   2. runs the rest of the pipeline,
 *   3. saves the session and writes the session cookie onto the response.
 *
 * Stateless across requests — all state lives in the per-request Store instance.
 */
final class StartSessionStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();
        if ($container === null || !$container->has(SessionPort::class)) {
            return $next($request); // session not configured for this request
        }

        $session = $container->make(SessionPort::class);
        if (!$session instanceof SessionPort) {
            return $next($request);
        }

        $cookieName    = (string) (env('SESSION_COOKIE') ?: 'hkm_session');
        $incoming      = $request->cookie($cookieName);
        $hadCookie     = $incoming !== null && $incoming !== '';
        $path          = (string) (env('SESSION_COOKIE_PATH') ?: '/');
        $domain        = (env('SESSION_COOKIE_DOMAIN') ?: null) ?: null;

        // Cookie driver: the session state lives in the cookie itself, so bind the
        // client context (for optional fingerprint validation) and feed the incoming
        // cookie value to the handler before loading.
        $cookieHandler = $this->cookieHandler($session);
        if ($cookieHandler !== null) {
            $cookieHandler->bindClient($request->userAgent(), $request->ip());
            $cookieHandler->prime($incoming);
        }

        $session->start($incoming);

        $response = $next($request);

        // Lazy persistence: a fresh visitor that never used the session leaves no
        // server file (and, for the cookie driver, no payload) and gets no cookie —
        // stateless traffic (APIs, bots) stays clean.
        if (!$session->shouldPersist()) {
            return $response;
        }

        $session->save();

        // Cookie driver carries the serialized payload; server drivers carry the id.
        $value = $cookieHandler !== null ? $cookieHandler->outgoing() : $session->id();

        // The cookie driver could not emit a payload (e.g. it exceeded the size
        // ceiling). If the browser already holds a session cookie it would keep a
        // now-stale session alive, so actively expire it rather than leave it.
        if ($value === null) {
            return $hadCookie ? $response->withoutCookie($cookieName, $path, $domain) : $response;
        }

        return $response->withCookie(
            name:     $cookieName,
            value:    $value,
            maxAge:   (int) (env('SESSION_LIFETIME') ?: 7200),
            path:     $path,
            domain:   $domain,
            secure:   $this->secure($request),
            httpOnly: true,
            sameSite: (string) (env('SESSION_SAMESITE') ?: 'Lax'),
        );
    }

    /**
     * Whether to flag the session cookie Secure. SESSION_SECURE forces it on/off;
     * unset (or "auto") follows the request scheme so dev over plain HTTP still works.
     */
    private function secure(Request $request): bool
    {
        $flag = strtolower((string) (env('SESSION_SECURE') ?: 'auto'));

        return match ($flag) {
            'true', '1', 'on', 'yes'   => true,
            'false', '0', 'off', 'no'  => false,
            default                    => $request->isSecure(),
        };
    }

    /** The cookie-backed handler when the cookie driver is active, else null. */
    private function cookieHandler(SessionPort $session): ?CookieBackedHandler
    {
        if (!$session instanceof Store) {
            return null;
        }

        $handler = $session->handler();

        return $handler instanceof CookieBackedHandler ? $handler : null;
    }
}
