<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Application\Services\IntrospectionService;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Infrastructure\Http\Concerns\SpeaksOAuth;
use Project\Http\Controllers\ApiController;

/**
 * POST /oauth/introspect (RFC 7662) and POST /oauth/revoke (RFC 7009).
 *
 * Both require a confidential client to authenticate (Basic or body creds) — an
 * unauthenticated caller must not be able to probe token validity.
 */
final class IntrospectionController extends ApiController
{
    use SpeaksOAuth;

    public function __construct(
        private readonly IntrospectionService $introspection,
        private readonly ClientStore $clients,
        private readonly HashingPort $hasher,
    ) {
    }

    public function introspect(): Response
    {
        $request = $this->resolveRequest();
        try {
            $this->authenticateClient($request);
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }

        $result = $this->introspection->introspect(trim((string) $request->input('token')));

        return $this->noStore(Response::json($result));
    }

    public function revoke(): Response
    {
        $request = $this->resolveRequest();
        try {
            $this->authenticateClient($request);
        } catch (OAuthException $e) {
            return $this->oauthError($e);
        }

        $this->introspection->revoke(trim((string) $request->input('token')));

        // RFC 7009 §2.2 — success regardless of whether the token existed.
        return $this->noStore(Response::json(['ok' => true]));
    }

    private function authenticateClient(Request $request): void
    {
        $basic = $this->basicClient($request);
        [$clientId, $secret] = $basic ?? [trim((string) $request->input('client_id')), (string) $request->input('client_secret')];

        $client = $clientId === '' ? null : $this->clients->find($clientId);
        if ($client === null || $client->revoked || $client->secretHash === null
            || !$this->hasher->check((string) $secret, $client->secretHash)) {
            throw OAuthException::invalidClient();
        }
    }
}
