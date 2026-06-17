<?php

declare(strict_types=1);

namespace Plugins\Cookie\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;

/**
 * CookieJar — queue outgoing cookies during a request, flush them onto the
 * response at the end (GDA rewrite of the 0.3 CookieJar + EncryptCookies +
 * AddQueuedCookiesToResponse filters, merged into one request-scoped service).
 *
 * Modules inject the jar and queue cookies without touching the Response:
 *   $jar->queue('theme', 'dark', maxAge: 3600);
 *   $jar->forget('legacy');
 *
 * When an EncryptionPort is available, queued cookie VALUES are encrypted on
 * flush (authenticated, tamper-evident) unless the cookie name is exempt.
 * Reading an encrypted incoming cookie is symmetric: decrypt($request->cookie(...)).
 */
final class CookieJar
{
    /** @var array<string, array{value: string, maxAge: int, path: string, domain: ?string, secure: bool, httpOnly: bool, sameSite: string, raw: bool}> */
    private array $queued = [];

    /**
     * @param list<string> $exempt cookie names whose values are NOT encrypted
     */
    public function __construct(
        private readonly ?EncryptionPort $encrypter = null,
        private readonly array $exempt = [],
    ) {}

    public function queue(
        string $name,
        string $value,
        int $maxAge = 0,
        string $path = '/',
        ?string $domain = null,
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
        bool $raw = false,
    ): void {
        $this->queued[$name] = compact('value', 'maxAge', 'path', 'domain', 'secure', 'httpOnly', 'sameSite', 'raw');
    }

    /** Queue a cookie that expires in ~5 years. */
    public function forever(string $name, string $value, string $path = '/', ?string $domain = null): void
    {
        $this->queue($name, $value, maxAge: 60 * 60 * 24 * 365 * 5, path: $path, domain: $domain);
    }

    public function forget(string $name, string $path = '/', ?string $domain = null): void
    {
        $this->queue($name, '', maxAge: -1, path: $path, domain: $domain, raw: true);
    }

    public function hasQueued(string $name): bool
    {
        return isset($this->queued[$name]);
    }

    /**
     * Read an incoming cookie and transparently decrypt it (the symmetric
     * counterpart to applyTo()'s encryption). Exempt cookies are returned raw,
     * mirroring how they were written. Returns null when absent or tampered.
     */
    public function read(Request $request, string $name): ?string
    {
        $raw = $request->cookie($name);
        if ($raw === null) {
            return null;
        }
        return $this->isExempt($name) ? $raw : $this->decrypt($raw);
    }

    /** Decrypt an incoming cookie value; returns null on tampering/format error. */
    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->encrypter === null) {
            return $value;
        }
        try {
            return $this->encrypter->decryptString($value);
        } catch (\Throwable) {
            return null; // tampered or not encrypted with our key
        }
    }

    /**
     * Apply every queued cookie to the response (encrypting non-exempt, non-cleared
     * values when an EncryptionPort is configured) and return the new response.
     */
    public function applyTo(Response $response): Response
    {
        foreach ($this->queued as $name => $c) {
            $value = $c['value'];
            $clearing = $c['maxAge'] < 0 || $value === '';

            if (!$clearing && !$c['raw'] && $this->encrypter !== null && !$this->isExempt($name)) {
                $value = $this->encrypter->encryptString($value);
            }

            $response = $response->withCookie(
                name:     $name,
                value:    $value,
                maxAge:   $c['maxAge'],
                path:     $c['path'],
                domain:   $c['domain'],
                secure:   $c['secure'],
                httpOnly: $c['httpOnly'],
                sameSite: $c['sameSite'],
            );
        }
        return $response;
    }

    private function isExempt(string $name): bool
    {
        return in_array($name, $this->exempt, true);
    }
}
