<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

/**
 * SiteUri — base-URL aware ABSOLUTE URL generation for the running site.
 *
 * WHY THIS EXISTS (when to reach for it):
 *  - Anything that must be absolute and must NOT hardcode the host: OAuth
 *    `redirect_uri` callbacks (see plugins/SocialAuth), password-reset / email
 *    verification links (Mail), webhooks, sitemaps, RSS, asset URLs.
 *  - Multi-tenant / domain-resolved deploys: the host comes from the request
 *    (which DomainResolver already validated), so the same code emits correct
 *    links per domain without configuration drift.
 *
 * Derive it from a request with SiteUri::fromRequest() (or Request::site()), then
 * `->to('auth/callback')` for an absolute URL. Immutable and side-effect free.
 */
final readonly class SiteUri
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Base URL = scheme://host[:port] of the incoming request. */
    public static function fromRequest(Request $request): self
    {
        return new self($request->scheme() . '://' . $request->getHttpHost());
    }

    public function base(): string
    {
        return $this->baseUrl;
    }

    /**
     * Absolute URL for a path, with an optional query.
     *
     * @param array<string, mixed> $query
     */
    public function to(string $path = '', array $query = []): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /** Absolute URL for a static asset (alias of to() for readability). */
    public function asset(string $path): string
    {
        return $this->to($path);
    }

    /** As a PSR-7 Uri value object (for further immutable manipulation). */
    public function uri(string $path = ''): Uri
    {
        return new Uri($this->to($path));
    }
}
