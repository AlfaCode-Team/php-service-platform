<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

/**
 * Negotiate — content negotiation over a request's Accept-* headers.
 *
 * WHY THIS EXISTS (when to reach for it):
 *  - i18n: pick the response locale from Accept-Language against what the app
 *    actually ships — e.g. a locale-resolver stage feeding plugins/I18n instead
 *    of a single static APP_LOCALE. `$request->negotiate()->language(['en','fr'])`.
 *  - Representation selection: serve JSON vs HTML vs CSV from one endpoint by the
 *    best acceptable media type, beyond the boolean Request::wantsJson().
 *  - Transport: choose a response charset / compression encoding (gzip, br) the
 *    client actually accepts.
 *
 * Each method returns the best supported value, falling back to the first
 * supported option (or the supplied default) when the client expresses no usable
 * preference — the conservative behaviour servers want. Stateless / immutable.
 */
final readonly class Negotiate
{
    public function __construct(private Request $request) {}

    public static function for(Request $request): self
    {
        return new self($request);
    }

    /**
     * Best matching media (content) type.
     *
     * @param string[] $supported
     */
    public function media(array $supported, ?string $default = null): ?string
    {
        return $this->request->accepts($supported) ?? $default ?? ($supported[0] ?? null);
    }

    /**
     * Best matching charset.
     *
     * @param string[] $supported
     */
    public function charset(array $supported, ?string $default = null): ?string
    {
        return $this->best($this->request->getCharsets(), $supported, $default);
    }

    /**
     * Best matching content encoding (gzip, br, …).
     *
     * @param string[] $supported
     */
    public function encoding(array $supported, ?string $default = null): ?string
    {
        return $this->best($this->request->getEncodings(), $supported, $default);
    }

    /**
     * Best matching language.
     *
     * @param string[] $supported
     */
    public function language(array $supported, ?string $default = null): ?string
    {
        return $this->best($this->request->getLanguages(), $supported, $default);
    }

    /**
     * @param string[] $accepted client-ranked acceptable values
     * @param string[] $supported values the server can produce
     */
    private function best(array $accepted, array $supported, ?string $default): ?string
    {
        if ($supported === []) {
            return $default;
        }
        foreach ($accepted as $value) {
            foreach ($supported as $candidate) {
                if (strtolower($value) === strtolower($candidate) || $value === '*') {
                    return $candidate;
                }
            }
        }

        return $default ?? $supported[0];
    }
}
