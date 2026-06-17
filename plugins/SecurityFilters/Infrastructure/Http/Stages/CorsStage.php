<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Cross-Origin Resource Sharing (GDA rewrite of the 0.3 Cors filter + CorsService).
 *
 * Zero-dependency: no Symfony/CodeIgniter CORS service. Configuration is read
 * from env so the router stays in module.json:
 *
 *   CORS_ALLOWED_ORIGINS   "*" | "https://a.com,https://b.com"
 *   CORS_ALLOWED_METHODS   default "GET,POST,PUT,PATCH,DELETE,OPTIONS"
 *   CORS_ALLOWED_HEADERS   default "Content-Type,Authorization,X-Requested-With"
 *   CORS_EXPOSED_HEADERS   default "" (none)
 *   CORS_MAX_AGE           preflight cache seconds, default 0
 *   CORS_ALLOW_CREDENTIALS "true"|"false", default false
 *
 * Runs early (after.security): an OPTIONS preflight is answered with 204 + the
 * CORS headers and never reaches auth/modules. Actual requests pass through and
 * the response is decorated with the Access-Control-* headers on the way out.
 *
 * When credentials are allowed the wildcard origin is echoed back as the exact
 * request Origin (the spec forbids "*" with credentials).
 */
final class CorsStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $origin = $request->header('origin');

        // Not a cross-origin request — nothing to do.
        if ($origin === null || $origin === '') {
            return $next($request);
        }

        $allowOrigin = $this->resolveAllowedOrigin($origin);
        if ($allowOrigin === null) {
            // Origin not permitted: for preflight short-circuit, otherwise pass
            // through without CORS headers (the browser will block the read).
            return $request->isMethod('OPTIONS') ? Response::empty(204) : $next($request);
        }

        $headers = $this->corsHeaders($allowOrigin);

        if ($request->isMethod('OPTIONS')) {
            $headers['Access-Control-Allow-Methods'] = (string) (env('CORS_ALLOWED_METHODS')
                ?: 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
            $headers['Access-Control-Allow-Headers'] = $request->header('access-control-request-headers')
                ?? (string) (env('CORS_ALLOWED_HEADERS') ?: 'Content-Type,Authorization,X-Requested-With');
            $maxAge = (int) (env('CORS_MAX_AGE') ?: 0);
            if ($maxAge > 0) {
                $headers['Access-Control-Max-Age'] = (string) $maxAge;
            }
            return Response::empty(204)->withHeaders($headers);
        }

        $exposed = trim((string) (env('CORS_EXPOSED_HEADERS') ?: ''));
        if ($exposed !== '') {
            $headers['Access-Control-Expose-Headers'] = $exposed;
        }

        return $next($request)->withHeaders($headers);
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(string $allowOrigin): array
    {
        $headers = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Vary'                        => 'Origin',
        ];
        if ($this->allowsCredentials()) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        return $headers;
    }

    private function resolveAllowedOrigin(string $origin): ?string
    {
        $configured = trim((string) (env('CORS_ALLOWED_ORIGINS') ?: '*'));

        if ($configured === '*') {
            // Cannot send "*" together with credentials — echo the origin.
            return $this->allowsCredentials() ? $origin : '*';
        }

        foreach (explode(',', $configured) as $candidate) {
            if (strcasecmp(trim($candidate), $origin) === 0) {
                return $origin;
            }
        }
        return null;
    }

    private function allowsCredentials(): bool
    {
        return filter_var(env('CORS_ALLOW_CREDENTIALS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    }
}
