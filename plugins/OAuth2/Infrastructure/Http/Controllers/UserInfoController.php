<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Ports\UserInfoProvider;
use Project\Http\Controllers\ApiController;

/**
 * GET /oauth/userinfo — OIDC UserInfo endpoint (OIDC Core §5.3).
 *
 * Bearer-authenticated: the access token is verified by the platform JwtAuthLayer,
 * which attaches the Identity (scopes → permissions). Requires the `openid` scope.
 */
final class UserInfoController extends ApiController
{
    public function __construct(private readonly UserInfoProvider $userInfo)
    {
    }

    public function show(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('A valid access token is required.')
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        // Scopes are published into Identity.permissions namespaced as `scope:*`.
        if (!$identity->hasPermission('scope:openid')) {
            return Response::forbidden('The openid scope is required.');
        }

        // Strip the `scope:` prefix back to bare scope names for the provider.
        $scopes = [];
        foreach ($identity->permissions as $perm) {
            if (str_starts_with($perm, 'scope:')) {
                $scopes[] = substr($perm, 6);
            }
        }

        return $this->noStore(Response::json(
            $this->userInfo->claims($identity->userId, $scopes),
        ));
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }
}
