<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Http\Concerns;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * ManagesResponse — shared immutable accessors/mutators for every response type
 * (Response, JsonResponse, RedirectResponse, DownloadResponse).
 *
 * The host class must extend a Symfony Response (so $this->headers is a
 * ResponseHeaderBag). Every "with*" returns a clone; the original is untouched.
 */
trait ManagesResponse
{
    public function __clone(): void
    {
        $this->headers = clone $this->headers;
    }

    public function withHeader(string $name, string $value): static
    {
        $clone = clone $this;
        $clone->headers->set($name, $value);

        return $clone;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): static
    {
        $clone = clone $this;
        foreach ($headers as $name => $value) {
            $clone->headers->set($name, $value);
        }

        return $clone;
    }

    public function withStatus(int $status): static
    {
        $clone = clone $this;
        $clone->setStatusCode($status);

        return $clone;
    }

    /** Queue a Set-Cookie header with secure defaults. Returns a clone. */
    public function withCookie(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = Cookie::SAMESITE_LAX,
    ): static {
        $clone = clone $this;
        $clone->headers->setCookie(Cookie::create(
            name: $name,
            value: $value,
            expire: $maxAge === 0 ? 0 : time() + $maxAge,
            path: $path,
            domain: $domain,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
        ));

        return $clone;
    }

    /** Expire a cookie immediately. */
    public function withoutCookie(string $name, string $path = '/', ?string $domain = null): static
    {
        $clone = clone $this;
        $clone->headers->clearCookie($name, $path, $domain);

        return $clone;
    }

    public function status(): int
    {
        return $this->getStatusCode();
    }

    public function body(): string
    {
        return (string) $this->getContent();
    }

    /** @return array<string, string> flattened response headers (original case, excluding cookies) */
    public function headers(): array
    {
        $out = [];
        foreach ($this->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $out[$name] = \is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $out;
    }

    /** @return string[] raw Set-Cookie header lines (for Swoole adapters) */
    public function cookies(): array
    {
        return array_map(static fn (Cookie $c): string => (string) $c, $this->headers->getCookies());
    }
}
