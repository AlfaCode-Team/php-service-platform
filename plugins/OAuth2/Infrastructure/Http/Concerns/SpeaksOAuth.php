<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;

/**
 * Shared OAuth2 HTTP helpers: RFC-shaped error bodies and client Basic-auth
 * parsing. Token responses always carry the no-store cache directives the spec
 * requires (RFC 6749 §5.1).
 */
trait SpeaksOAuth
{
    protected function oauthError(OAuthException $e): Response
    {
        $response = Response::json($e->toArray(), $e->status);

        // RFC 6749 §5.2 — invalid_client over Basic auth must include WWW-Authenticate.
        if ($e->error === 'invalid_client') {
            $response = $response->withHeader('WWW-Authenticate', 'Basic realm="oauth"');
        }

        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    protected function noStore(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
    }

    /**
     * Extract [client_id, client_secret] from an HTTP Basic Authorization header.
     *
     * @return array{0:string,1:string}|null
     */
    protected function basicClient(Request $request): ?array
    {
        $header = $request->header('Authorization') ?? '';
        if (!str_starts_with($header, 'Basic ')) {
            return null;
        }

        $decoded = base64_decode(trim(substr($header, 6)), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        [$id, $secret] = explode(':', $decoded, 2);

        // Credentials are form-urlencoded inside Basic per RFC 6749 §2.3.1.
        return [urldecode($id), urldecode($secret)];
    }
}
