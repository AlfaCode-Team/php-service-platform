<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\RefreshTokenServiceContract;
use Plugins\Tenancy\Domain\Exceptions\InvalidRefreshTokenException;
use Project\Http\Controllers\ApiController;

/**
 * Refresh-token rotation endpoint. UNAUTHENTICATED by design — the refresh token
 * in the body IS the credential. Rotation is one-time-use and returns a fresh
 * access JWT plus a new refresh token; a revoked/expired token (or a revoked
 * tenant seat) yields 401 so the client must re-authenticate.
 */
final class AuthTokenController extends ApiController
{
    public function __construct(
        private readonly RefreshTokenServiceContract $refreshTokens,
    ) {}

    /** POST /ajx/auth/refresh  { "token": "…" } */
    public function refresh(): Response
    {
        $request = $this->resolveRequest();
        $token   = trim((string) $request->input('token'));
        if ($token === '') {
            return $this->unprocessable(['token' => 'A refresh token is required.']);
        }

        try {
            $rotation = $this->refreshTokens->rotate($token, $request->ip());
        } catch (InvalidRefreshTokenException $e) {
            return Response::unauthorized($e->getMessage());
        }

        return $this->ok($rotation->toArray());
    }

    /** POST /ajx/auth/logout  { "token": "…" } — revoke a single refresh token. */
    public function logout(): Response
    {
        $request = $this->resolveRequest();
        $token   = trim((string) $request->input('token'));
        if ($token !== '') {
            $this->refreshTokens->revoke($token);
        }

        return $this->noContent();
    }
}
