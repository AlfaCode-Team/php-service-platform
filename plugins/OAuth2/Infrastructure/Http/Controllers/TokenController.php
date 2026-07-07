<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Services\TokenService;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Infrastructure\Http\Concerns\SpeaksOAuth;
use Project\Http\Controllers\ApiController;

/**
 * POST /oauth/token — the OAuth2 token endpoint (RFC 6749 §3.2).
 *
 * Supports authorization_code (+PKCE), client_credentials, refresh_token and
 * password grants. Unauthenticated by design — the client authenticates itself
 * via Basic auth or body credentials inside the grant.
 */
final class TokenController extends ApiController
{
    use SpeaksOAuth;

    public function __construct(private readonly TokenService $tokens)
    {
    }

    public function issue(): Response
    {
        $request = $this->resolveRequest();

        try {
            $body = $this->tokens->handle($request->all(), $this->basicClient($request));
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }

        return $this->noStore(Response::json($body));
    }
}
