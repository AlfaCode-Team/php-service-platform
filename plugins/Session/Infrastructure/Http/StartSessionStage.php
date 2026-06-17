<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

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

        $cookieName = (string) (env('SESSION_COOKIE') ?: 'hkm_session');
        $session->start($request->cookie($cookieName));

        $response = $next($request);

        // Lazy persistence: a fresh visitor that never used the session leaves
        // no file and gets no cookie — stateless traffic (APIs, bots) stays clean.
        if (!$session->shouldPersist()) {
            return $response;
        }

        $session->save();

        return $response->withCookie(
            name:     $cookieName,
            value:    $session->id(),
            maxAge:   (int) (env('SESSION_LIFETIME') ?: 7200),
            secure:   $request->isSecure(),
            httpOnly: true,
            sameSite: (string) (env('SESSION_SAMESITE') ?: 'Lax'),
        );
    }
}
