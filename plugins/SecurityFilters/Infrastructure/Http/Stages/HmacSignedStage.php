<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * HMAC-SHA256 request-signing validator for mutating API endpoints.
 *
 * GDA rewrite of the 0.3 HmacSigned filter. Registered as an `after.security`
 * hook; it only enforces on mutating methods whose path starts with
 * HMAC_PROTECTED_PREFIX, so the router effectively decides when it runs.
 *
 * Signature = HMAC-SHA256(METHOD\nPATH\nTIMESTAMP\nRAW_BODY, REQUEST_SIGNING_SECRET)
 *
 * Required request headers: X-Timestamp (unix int), X-Signature (64 hex chars).
 */
final class HmacSignedStage implements HttpStageContract
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const MIN_SECRET_LEN = 32;

    public function handle(Request $request, callable $next): Response
    {
        if (!$this->applies($request)) {
            return $next($request);
        }

        $timestamp = (string) ($request->header('X-Timestamp') ?? '');
        $signature = (string) ($request->header('X-Signature') ?? '');

        if ($timestamp === '' || $signature === '') {
            return Response::forbidden('Request signature headers (X-Timestamp, X-Signature) are required.');
        }
        if (strlen($signature) !== 64 || !ctype_xdigit($signature)) {
            return Response::forbidden('Request signature is invalid.');
        }
        if (!ctype_digit($timestamp)) {
            return Response::forbidden('X-Timestamp must be a Unix timestamp integer.');
        }

        $maxSkew = max(5, (int) (env('HMAC_MAX_SKEW') ?: 30));
        if (abs(time() - (int) $timestamp) > $maxSkew) {
            return Response::forbidden('Request timestamp is out of range. Ensure the device clock is synchronized.');
        }

        $secret = (string) (env('REQUEST_SIGNING_SECRET') ?: '');
        if (strlen($secret) < self::MIN_SECRET_LEN) {
            return Response::forbidden('Server signing configuration error.');
        }

        $payload = strtoupper($request->method()) . "\n"
            . $request->path() . "\n"
            . $timestamp . "\n"
            . $request->rawBody();

        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, strtolower($signature))) {
            return Response::forbidden('Request signature is invalid.');
        }

        return $next($request);
    }

    private function applies(Request $request): bool
    {
        if (!in_array(strtoupper($request->method()), self::MUTATING, true)) {
            return false;
        }
        $prefix = (string) (env('HMAC_PROTECTED_PREFIX') ?: '/api');
        return str_starts_with($request->path(), $prefix);
    }
}
