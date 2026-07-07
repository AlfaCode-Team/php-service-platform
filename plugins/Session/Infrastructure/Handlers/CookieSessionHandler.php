<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Handlers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use Plugins\Session\Infrastructure\Handlers\Contracts\CookieBackedHandler;

/**
 * Cookie-backed session handler — the session lives entirely in its own cookie.
 *
 * Unlike the file/array handlers there is NO server-side store: the serialized
 * attribute bag is protected and carried in the session cookie. The session id
 * is unused for lookup.
 *
 * ## Wire format
 *
 *   protected := encrypter.encryptString(envelope)            (encrypted path)
 *              | base64url(envelope) "." base64url(hmac)      (signed path)
 *
 *   envelope (JSON, pre-protection):
 *     { "v":1, "t":<issued-at>, "a":<last-active>, "f":<fp|null>, "c":<bool>, "d":<data> }
 *       v  schema version (forward-compat / rotation)
 *       t  absolute issue time      → enforces lifetime
 *       a  last activity time       → enforces idle timeout
 *       f  client fingerprint hash  → optional theft/replay defence
 *       c  whether d is deflated    → transparent compression
 *       d  serialized attribute bag (raw, or base64(gzdeflate) when c=true)
 *
 * ## Security
 *
 *   - With an EncryptionPort the cookie is confidential AND authenticated
 *     (AEAD); decryption throws on tampering → treated as a fresh session.
 *   - Without one, the envelope is HMAC-SHA256 signed with the configured key
 *     and verified with hash_equals() (constant time). Readable but tamper-evident.
 *   - Absolute lifetime and idle timeout are BOTH enforced on read, server-side,
 *     independent of the browser honouring Max-Age.
 *   - Optional fingerprint binding rejects a cookie presented with a different
 *     User-Agent/IP than the one it was issued to.
 *   - A configurable size ceiling drops an oversized outgoing cookie rather than
 *     emit an invalid Set-Cookie (use the file/Redis driver for large state).
 *
 * Stateless across requests — every byte of state lives in the per-request
 * instance and the cookie, so it is OpenSwoole-safe.
 */
final class CookieSessionHandler implements \SessionHandlerInterface, CookieBackedHandler
{
    private const VERSION = 1;

    /** Decrypted/verified incoming data string for the next read() ('' = none). */
    private string $incoming = '';

    /** Protected outgoing cookie value produced by write(), or null. */
    private ?string $outgoing = null;

    /** Absolute issue time carried across from the incoming cookie (preserved on write). */
    private ?int $issuedAt = null;

    /** Current client fingerprint (when binding is enabled). */
    private ?string $fingerprint = null;

    public function __construct(
        private readonly CookieSessionConfig $config = new CookieSessionConfig(),
    ) {
        if ($this->config->requireAuthentication && !$this->config->isAuthenticated()) {
            throw new KernelException(
                'Cookie session driver requires authentication: bind an EncryptionPort '
                . 'or set a signing key (APP_KEY). Refusing to issue unsigned session cookies.',
                layer: 'session.cookie',
            );
        }

        if ($this->config->requireEncryption && !$this->config->isEncrypted()) {
            throw new KernelException(
                'Cookie session driver requires encryption: bind an EncryptionPort. '
                . 'Refusing to issue signed-but-readable session cookies.',
                layer: 'session.cookie',
            );
        }
    }

    public function bindClient(?string $userAgent, ?string $ipAddress): void
    {
        if (!$this->config->bindsFingerprint()) {
            return;
        }

        // Build the material from ONLY the configured components, then hash it with
        // the signing key so the fingerprint itself is opaque and unforgeable.
        $parts = [];
        foreach ($this->config->fingerprint as $component) {
            $parts[] = match ($component) {
                CookieSessionConfig::FP_USER_AGENT => 'ua:' . ($userAgent ?? ''),
                CookieSessionConfig::FP_IP         => 'ip:' . ($ipAddress ?? ''),
                default                            => '',
            };
        }

        $this->fingerprint = hash_hmac('sha256', implode('|', $parts), $this->config->signingKey ?: 'fp');
    }

    public function prime(?string $rawCookie): void
    {
        $this->incoming = $this->decode($rawCookie);
    }

    public function outgoing(): ?string
    {
        return $this->outgoing;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        return $this->incoming;
    }

    public function write(string $id, string $data): bool
    {
        $this->outgoing = $this->encode($data);

        return true;
    }

    public function destroy(string $id): bool
    {
        $this->incoming = '';
        $this->outgoing = null;
        $this->issuedAt = null;

        return true;
    }

    public function gc(int $maxLifetime): int|false
    {
        return 0; // nothing server-side to collect
    }

    // ── Encoding ────────────────────────────────────────────────────────────────

    /** Build, protect, and size-check the outgoing cookie payload. */
    private function encode(string $data): ?string
    {
        $now        = time();
        $compressed = false;

        if ($this->config->compressThreshold > 0
            && strlen($data) > $this->config->compressThreshold
            && function_exists('gzdeflate')
        ) {
            $packed = gzdeflate($data, 9);
            if ($packed !== false && strlen($packed) < strlen($data)) {
                $data       = $packed;
                $compressed = true;
            }
        }

        // base64 the data ALWAYS so the envelope stays valid JSON even when the
        // inner serialization (e.g. SESSION_SERIALIZATION=php) produces binary —
        // json_encode would otherwise fail on non-UTF-8 bytes and drop the cookie.
        $envelope = json_encode([
            'v' => self::VERSION,
            't' => $this->issuedAt ?? $now,   // preserve the original issue time
            'a' => $now,                      // refresh last-active on every save
            'f' => $this->fingerprint,
            'c' => $compressed,
            'd' => base64_encode($data),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($envelope === false) {
            return null;
        }

        $payload = $this->protect($envelope);

        return strlen($payload) > $this->config->maxBytes ? null : $payload;
    }

    /** Encrypt (preferred) or sign the envelope. */
    private function protect(string $envelope): string
    {
        if ($this->config->encrypter !== null) {
            return $this->config->encrypter->encryptString($envelope);
        }

        $body = $this->b64UrlEncode($envelope);
        $sig  = $this->b64UrlEncode($this->sign($envelope));

        return $body . '.' . $sig;
    }

    // ── Decoding ──────────────────────────────────────────────────────────────────

    /** Verify/decrypt, validate every claim, and return the raw data string. */
    private function decode(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }

        $envelope = $this->unprotect($raw);
        if ($envelope === null) {
            return '';
        }

        $decoded = json_decode($envelope, true);
        if (!is_array($decoded)
            || ($decoded['v'] ?? null) !== self::VERSION
            || !isset($decoded['t'], $decoded['a'], $decoded['d'])
            || !is_string($decoded['d'])
        ) {
            return '';
        }

        $now = time();

        // Absolute lifetime.
        if ($this->config->lifetime > 0 && (int) $decoded['t'] + $this->config->lifetime < $now) {
            return '';
        }
        // Idle timeout.
        if ($this->config->idleTimeout > 0 && (int) $decoded['a'] + $this->config->idleTimeout < $now) {
            return '';
        }
        // Fingerprint binding — constant-time compare.
        if ($this->config->bindsFingerprint()) {
            $expected = is_string($decoded['f'] ?? null) ? $decoded['f'] : '';
            if ($this->fingerprint === null || !hash_equals($expected, $this->fingerprint)) {
                return '';
            }
        }

        // Carry the original issue time forward so re-saving never extends the
        // absolute lifetime (idle timeout still slides via 'a').
        $this->issuedAt = (int) $decoded['t'];

        // 'd' is always base64; inflate afterwards when it was compressed.
        $data = base64_decode($decoded['d'], true);
        if ($data === false) {
            return '';
        }

        if (($decoded['c'] ?? false) === true) {
            $data = function_exists('gzinflate') ? @gzinflate($data) : false;
            if ($data === false) {
                return '';
            }
        }

        return $data;
    }

    /** Decrypt or verify-and-strip the signature; null on any failure. */
    private function unprotect(string $raw): ?string
    {
        if ($this->config->encrypter !== null) {
            try {
                return $this->config->encrypter->decryptString($raw);
            } catch (\Throwable) {
                return null; // tampered or wrong key
            }
        }

        $dot = strrpos($raw, '.');
        if ($dot === false) {
            return null;
        }

        $envelope  = $this->b64UrlDecode(substr($raw, 0, $dot));
        $signature = $this->b64UrlDecode(substr($raw, $dot + 1));
        if ($envelope === null || $signature === null) {
            return null;
        }

        return hash_equals($this->sign($envelope), $signature) ? $envelope : null;
    }

    // ── Primitives ────────────────────────────────────────────────────────────────

    private function sign(string $envelope): string
    {
        return hash_hmac('sha256', $envelope, $this->config->signingKey, true);
    }

    private function b64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
