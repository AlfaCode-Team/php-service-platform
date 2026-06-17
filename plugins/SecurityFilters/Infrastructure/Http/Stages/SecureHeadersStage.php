<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Adds common OWASP security headers to every response (GDA rewrite of the 0.3
 * SecureHeaders filter). Registered in the observability band (after.execute) so
 * it decorates the outgoing response regardless of which module produced it.
 *
 * HSTS is only emitted over HTTPS (sending it over plain HTTP is a no-op at best
 * and misleading at worst). A Content-Security-Policy can be supplied via the
 * CONTENT_SECURITY_POLICY env var; it is omitted when empty so projects opt in.
 */
final class SecureHeadersStage implements HttpStageContract
{
    /** @var array<string, string> */
    private const DEFAULT_HEADERS = [
        'X-Frame-Options'                    => 'SAMEORIGIN',
        'X-Content-Type-Options'             => 'nosniff',
        'X-Permitted-Cross-Domain-Policies'  => 'none',
        'Referrer-Policy'                    => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy'         => 'same-origin',
    ];

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);
        $headers  = self::DEFAULT_HEADERS;

        if ($request->isSecure()) {
            $maxAge = (int) (env('HSTS_MAX_AGE') ?: 31536000);
            $headers['Strict-Transport-Security'] = 'max-age=' . $maxAge . '; includeSubDomains';
        }

        $csp = trim((string) (env('CONTENT_SECURITY_POLICY') ?: ''));
        if ($csp !== '') {
            $headers['Content-Security-Policy'] = $csp;
        }

        return $response->withHeaders($headers);
    }
}
