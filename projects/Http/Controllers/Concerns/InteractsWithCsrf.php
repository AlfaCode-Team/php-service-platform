<?php

declare(strict_types=1);

namespace Project\Http\Controllers\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;

/**
 * CSRF protection helper for request-scoped controllers.
 *
 * This trait provides:
 * - CSRF token generation bound to a per-client cookie "binding"
 * - CSRF token validation against the kernel CsrfTokenLayer
 *
 * The CSRF system works as follows:
 *
 * 1. A per-client "binding" value is stored in a cookie (csrf_bind by default)
 * 2. A CSRF token is generated using:
 *      - application secret (APP_KEY)
 *      - binding value
 *      - optional action context
 *      - configured lifetime
 * 3. The CsrfTokenLayer validates incoming requests using the same inputs
 *
 * The binding cookie is:
 * - generated once per client
 * - stored as RAW (not encrypted)
 * - httpOnly (not accessible via JS)
 *
 * This ensures the browser automatically returns it on subsequent requests.
 *
 * This trait assumes:
 * - a request is already attached to the controller (RequestAware / HasRequest)
 * - cookie access is available through InteractsWithCookies
 */
trait InteractsWithCsrf
{

    use HasRequest;
    use InteractsWithCookies;

    /**
     * MUST match CsrfTokenLayer lifetime configuration.
     */
    protected const LIFETIME = 43200;

    /**
     * Optional action context used to scope CSRF tokens.
     */
    protected string $csrfAction = '';

    /**
     * Cached binding value for the current request lifecycle.
     */
    protected ?string $bind = null;


    /**
     * Returns or creates the per-client CSRF binding.
     *
     * The binding is stored in a cookie and is required to generate and
     * validate CSRF tokens consistently across requests.
     *
     * If missing, a new binding is generated and queued as a RAW cookie.
     */
    private function binding(): string
    {
        if ($this->bind !== null) {
            return $this->bind;
        }

        $cookieName = (string) (env('CSRF_BIND_COOKIE') ?: 'csrf_bind');
        // Read the verbatim cookie (NOT via CookieJar, which would try to decrypt).
        $bind = $this->cookie($cookieName);
        if ($bind === null || $bind === '') {
            $bind = bin2hex(random_bytes(16));
            // raw: true → stored unencrypted so the layer's header read matches.
            // Stays httpOnly (default) — JS never needs the binding, only the token.
            $this->cookieJar()?->queue($cookieName, $bind, raw: true);
        }

        return $this->bind = $bind;
    }

    /**
     * Generates a CSRF token for the current request context.
     *
     * The token is bound to:
     * - application secret (APP_KEY)
     * - client binding cookie
     * - configured lifetime
     * - optional action context
     *
     * Returns an empty string if the application secret is missing.
     */
    protected function _csrfToken(): string
    {
        $secret = $this->secret();
        if ($secret === '') {
            return ''; // fail-closed: no secret ⇒ no usable token (layer denies anyway)
        }

        return CsrfTokenLayer::make(
            $secret,
            $this->binding(),
            env('CSRF_LIFETIME', static::LIFETIME),
            $this->csrfAction
        );
    }
    /**
     * Returns the application secret used for CSRF signing.
     */
    private function secret(): string
    {
        return (string) (env('APP_KEY') ?: '');
    }
}
