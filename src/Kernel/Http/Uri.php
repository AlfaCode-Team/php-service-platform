<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http;

use Psr\Http\Message\UriInterface;

/**
 * Uri — immutable URI value object implementing PSR-7 UriInterface.
 *
 * WHY THIS EXISTS (when to reach for it):
 *  - Safe URL manipulation: building a redirect target from the current request
 *    without string surgery — e.g. `$request->uri()->withPath('/login')->withQuery('')`.
 *  - Canonical URLs: strip the query / force https / drop a default port for
 *    cache keys, <link rel="canonical">, sitemaps, signed-URL bases.
 *  - Interop: implementing the PSR interface means it drops straight into any
 *    PSR-7 aware code (middleware, HTTP clients) without adapters.
 *
 * Get one from the current request via Request::uri(), or parse any string with
 * `new Uri($string)`. Every "with*" returns a new instance; nothing mutates.
 */
final class Uri implements UriInterface, \Stringable
{
    private string $scheme = '';
    private string $userInfo = '';
    private string $host = '';
    private ?int $port = null;
    private string $path = '';
    private string $query = '';
    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }
        $parts = parse_url($uri);
        if ($parts === false) {
            throw new \InvalidArgumentException("Unable to parse URI: {$uri}");
        }
        $this->scheme   = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $this->host     = isset($parts['host']) ? strtolower($parts['host']) : '';
        $this->port     = $this->filterPort($parts['port'] ?? null);
        $this->path     = $parts['path'] ?? '';
        $this->query    = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
        $this->userInfo = $parts['user'] ?? '';
        if (isset($parts['pass'])) {
            $this->userInfo .= ':' . $parts['pass'];
        }
    }

    /** Build a Uri from the current request's full URL. */
    public static function fromRequest(Request $request): self
    {
        return new self($request->fullUrl());
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }
        if ($this->port !== null) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): UriInterface
    {
        $clone = clone $this;
        $clone->scheme = strtolower($scheme);
        $clone->port = $clone->filterPort($clone->port);

        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        $clone = clone $this;
        $clone->userInfo = $password !== null && $password !== '' ? "{$user}:{$password}" : $user;

        return $clone;
    }

    public function withHost(string $host): UriInterface
    {
        $clone = clone $this;
        $clone->host = strtolower($host);

        return $clone;
    }

    public function withPort(?int $port): UriInterface
    {
        $clone = clone $this;
        $clone->port = $clone->filterPort($port);

        return $clone;
    }

    public function withPath(string $path): UriInterface
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public function withQuery(string $query): UriInterface
    {
        $clone = clone $this;
        $clone->query = ltrim($query, '?');

        return $clone;
    }

    public function withFragment(string $fragment): UriInterface
    {
        $clone = clone $this;
        $clone->fragment = ltrim($fragment, '#');

        return $clone;
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '' || $this->scheme === 'file') {
            $uri .= '//' . $authority;
        }
        $uri .= $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    /** Drop the port when it equals the scheme's default. */
    private function filterPort(?int $port): ?int
    {
        if ($port === null) {
            return null;
        }
        $defaults = ['http' => 80, 'https' => 443, 'ftp' => 21];

        return ($defaults[$this->scheme] ?? null) === $port ? null : $port;
    }
}
