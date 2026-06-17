<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;

/**
 * CsrfTokenLayer — stateless CSRF protection using the double-submit cookie pattern.
 *
 * Why this lives in the kernel:
 *   CSRF is a generic browser-form protection mechanism, independent of any
 *   authentication scheme. Every web app needs it. Token/JWT verification, on
 *   the other hand, belongs in an Auth module (to be provided by the project).
 *
 * Pattern:
 *   1. On every safe request, the project's front-end sets a cookie (e.g. `XSRF-TOKEN`)
 *      with a high-entropy random value. The same value is sent back on unsafe
 *      requests in a header (e.g. `X-CSRF-Token`) or a form field (`_csrf_token`).
 *   2. This layer compares the cookie value to the header/form value using
 *      hash_equals() (timing-safe). If they match, the request is allowed.
 *   3. Safe methods (GET/HEAD/OPTIONS) and exempt paths bypass the check.
 *
 * The layer is stateless: it does not store, generate, or rotate tokens — that
 * is the project's responsibility. The kernel only verifies the double-submit.
 *
 * NEVER throws — always returns a SecurityVerdict (the layer contract).
 */
final class CsrfTokenLayer implements SecurityLayerContract
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param string             $cookieName    name of the cookie holding the canonical token
     * @param string             $headerName    request header carrying the echoed token
     * @param string             $formField     fallback body/query field carrying the echoed token
     * @param list<string>       $exemptPaths   path prefixes that bypass the check (e.g. ['/api/webhooks'])
     * @param list<string>       $exemptMethods extra methods to treat as safe (kept lowercase-free; normalised below)
     */
    public function __construct(
        private readonly string $cookieName    = 'XSRF-TOKEN',
        private readonly string $headerName    = 'X-CSRF-Token',
        private readonly string $formField     = '_csrf_token',
        private readonly array  $exemptPaths   = [],
        private readonly array  $exemptMethods = [],
    ) {}

    public function check(Request $request): SecurityVerdict
    {
        $method = strtoupper($request->method());

        // Safe methods never require a CSRF token.
        if (in_array($method, self::SAFE_METHODS, true)) {
            return SecurityVerdict::allow($request);
        }
        foreach ($this->exemptMethods as $exempt) {
            if (strtoupper($exempt) === $method) {
                return SecurityVerdict::allow($request);
            }
        }

        // Exempt paths (e.g. machine-to-machine webhooks with their own signature).
        $path = $request->path();
        foreach ($this->exemptPaths as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return SecurityVerdict::allow($request);
            }
        }

        $cookieToken = $this->extractCookie($request, $this->cookieName);
        if ($cookieToken === null || $cookieToken === '') {
            return SecurityVerdict::deny(403, 'CSRF cookie missing.');
        }

        $submittedToken = $request->header($this->headerName)
            ?? (is_string($v = $request->input($this->formField)) ? $v : null);

        if ($submittedToken === null || $submittedToken === '') {
            return SecurityVerdict::deny(403, 'CSRF token missing.');
        }

        // Timing-safe comparison — never use === for token comparison.
        if (!hash_equals($cookieToken, $submittedToken)) {
            return SecurityVerdict::deny(403, 'CSRF token mismatch.');
        }

        return SecurityVerdict::allow($request);
    }

    /**
     * Parse the `Cookie` request header and extract a single cookie value.
     * Returns null if the cookie is absent or malformed.
     */
    private function extractCookie(Request $request, string $name): ?string
    {
        $header = $request->header('Cookie');
        if ($header === null || $header === '') {
            return null;
        }

        foreach (explode(';', $header) as $pair) {
            $pair = ltrim($pair);
            $eq   = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            if (substr($pair, 0, $eq) === $name) {
                return urldecode(substr($pair, $eq + 1));
            }
        }

        return null;
    }
}
