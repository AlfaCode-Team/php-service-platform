<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\User\API\Contracts\UserServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Stateful (session) login/logout for browser + AJAX clients.
 *
 * Flow:
 *   POST /auth/login   { identifier, password }  → verify credentials, open a
 *                       session login, return the user (200) or 401 on failure.
 *   POST /auth/logout                            → tear the session down (204).
 *   GET  /auth/me                                → current session identity.
 *
 * Credentials are verified by the User module (timing-safe + lockout); this
 * controller only turns a verified user into a session via AuthService. The
 * session Identity is rebuilt on later requests by SessionAuthStage, so the
 * `auth` route filter protects both token and session callers uniformly.
 *
 * CSRF: these are session/cookie endpoints (NOT under /api), so the kernel's
 * CsrfTokenLayer guards the POSTs — the client sends the HMAC token via the
 * `X-CSRF-Token` header (AJAX) or the `_csrf_token` field (form).
 */
final class SessionAuthController extends ApiController
{
    public function __construct(
        private readonly AuthServiceContract $auth,
        private readonly UserServiceContract $users,
        private readonly SessionPort $session,
    ) {
    }

    public function login(): Response
    {
        $request    = $this->resolveRequest();
        $identifier = trim((string) $request->input('identifier'));
        $password   = (string) $request->input('password');

        if ($identifier === '' || $password === '') {
            return $this->unprocessable([
                'identifier' => $identifier === '' ? 'An email or username is required.' : '',
                'password'   => $password === '' ? 'A password is required.' : '',
            ]);
        }

        $user = $this->users->verifyCredentials($identifier, $password);
        if ($user === null) {
            // Uniform message — never reveals whether the account exists or is locked.
            return Response::unauthorized('Invalid credentials.');
        }

        $this->auth->startSession($this->session, $user->id);

        return $this->ok(['user' => $user->toArray()]);
    }

    public function logout(): Response
    {
        $this->auth->endSession($this->session);

        return $this->noContent();
    }

    public function me(): Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return Response::unauthorized('Not authenticated.');
        }

        return $this->ok([
            'userId'      => $identity->userId,
            'tenantId'    => $identity->tenantId,
            'roles'       => $identity->roles,
            'permissions' => $identity->permissions,
            'via'         => $identity->tokenType,
        ]);
    }
}
