<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\OAuth2\Application\Ports\ScopeStore;
use Project\Http\Controllers\ApiController;

/**
 * GET /.well-known/oauth-authorization-server — authorization server metadata
 * (RFC 8414). Lets clients auto-discover endpoints and capabilities.
 */
final class DiscoveryController extends ApiController
{
    public function __construct(private readonly ScopeStore $scopes)
    {
    }

    public function metadata(): Response
    {
        $request = $this->resolveRequest();
        $base    = $request->site();

        return Response::json([
            'issuer'                                => (string) $base->to('/'),
            'authorization_endpoint'                => (string) $base->to('oauth/authorize'),
            'token_endpoint'                        => (string) $base->to('oauth/token'),
            'introspection_endpoint'                => (string) $base->to('oauth/introspect'),
            'revocation_endpoint'                   => (string) $base->to('oauth/revoke'),
            'device_authorization_endpoint'         => (string) $base->to('oauth/device_authorization'),
            'jwks_uri'                              => (string) $base->to('oauth/jwks'),
            'grant_types_supported'                 => [
                'authorization_code', 'client_credentials', 'refresh_token', 'password',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'response_types_supported'              => ['code'],
            'code_challenge_methods_supported'      => ['S256', 'plain'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'scopes_supported'                      => $this->scopes->all(),
        ])->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** GET /.well-known/openid-configuration — OIDC discovery (OIDC Discovery §3). */
    public function openidConfiguration(): Response
    {
        $base = $this->resolveRequest()->site();
        $algo = env('JWT_ALGO') ?: 'HS256';

        return Response::json([
            'issuer'                                => (string) $base->to('/'),
            'authorization_endpoint'                => (string) $base->to('oauth/authorize'),
            'token_endpoint'                        => (string) $base->to('oauth/token'),
            'userinfo_endpoint'                     => (string) $base->to('oauth/userinfo'),
            'device_authorization_endpoint'         => (string) $base->to('oauth/device_authorization'),
            'jwks_uri'                              => (string) $base->to('oauth/jwks'),
            'response_types_supported'              => ['code'],
            'subject_types_supported'               => ['public'],
            'id_token_signing_alg_values_supported' => [$algo],
            'grant_types_supported'                 => [
                'authorization_code', 'client_credentials', 'refresh_token', 'password',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
            'scopes_supported'                      => array_values(array_unique(['openid', ...$this->scopes->all()])),
            'code_challenge_methods_supported'      => ['S256', 'plain'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
        ])->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
