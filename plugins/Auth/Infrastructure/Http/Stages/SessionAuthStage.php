<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Auth\Application\Services\AuthService;

/**
 * SessionAuthStage — stateful authentication for browser (web + AJAX) requests.
 *
 * Why a stage and not a SecurityLayer: the session is only opened at
 * `after.load` (StartSessionStage, priority 20), which runs AFTER the
 * SecurityGateway. So token auth (JWT / PAT) attaches an Identity in the gateway,
 * and SESSION auth attaches one here — this stage is registered at `after.load`
 * with a priority just above StartSessionStage so the session is already loaded,
 * and before RouteFilterStage so the `auth` filter sees the resulting Identity.
 *
 * Precedence: a request that already carries a token-derived Identity (Bearer
 * JWT/PAT) is left untouched — the explicit credential wins. Anonymous requests
 * with a logged-in session get a `tokenType: 'session'` Identity. Everything else
 * stays guest so public routes keep working.
 */
final class SessionAuthStage implements HttpStageContract
{
    /**
     * Register right after StartSessionStage (20) so the session is loaded, and
     * before TenantContextStage (23) so a session-scoped tenant can route the DB.
     */
    public const PRIORITY = 22;

    public function handle(Request $request, callable $next): Response
    {
        // A token already authenticated this request — do not override it.
        $existing = $request->identity();
        if ($existing !== null && !$existing->isGuest()) {
            return $next($request);
        }

        $container = $request->container();
        if ($container === null || !$container->has(SessionPort::class)) {
            return $next($request);
        }

        $session = $container->make(SessionPort::class);
        if (!$session instanceof SessionPort) {
            return $next($request);
        }

        $userId = (string) $session->get(AuthService::SESSION_USER, '');
        if ($userId === '') {
            return $next($request); // anonymous session — stay guest
        }

        $identity = new Identity(
            userId:      $userId,
            tenantId:    (string) $session->get(AuthService::SESSION_TENANT, ''),
            roles:       $this->stringList($session->get(AuthService::SESSION_ROLES, [])),
            permissions: $this->stringList($session->get(AuthService::SESSION_PERMISSIONS, [])),
            tokenType:   'session',
        );

        return $next($request->withIdentity($identity));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
