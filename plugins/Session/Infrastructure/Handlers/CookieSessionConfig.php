<?php

declare(strict_types=1);

namespace Plugins\Session\Infrastructure\Handlers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;

/**
 * Immutable configuration for the cookie-backed session driver.
 *
 * Groups every security/size knob so CookieSessionHandler keeps a clean
 * constructor and the Provider has a single place to translate env → policy.
 *
 * Security posture (defence in depth):
 *   - Confidentiality + authenticity via EncryptionPort (preferred), OR
 *   - Authenticity-only via HMAC-SHA256 with $signingKey when no encrypter is
 *     bound (the payload is then readable but tamper-evident — never trust a
 *     cookie session without at least one of these).
 *   - Absolute lifetime AND idle timeout, both enforced server-side on read.
 *   - Optional client fingerprint binding — choose WHICH components to bind
 *     (User-Agent and/or IP) to balance theft defence against usability.
 *   - Optional transparent compression to fit more state under the ~4 KB limit.
 *
 * Two independent enforcement switches:
 *   - $requireAuthentication: refuse to build unless authenticated (encrypter
 *     OR signing key) — blocks issuing a wide-open, forgeable cookie.
 *   - $requireEncryption: refuse to build unless an EncryptionPort is present —
 *     blocks the signed-but-readable mode when the data must stay confidential.
 */
final readonly class CookieSessionConfig
{
    /** Fingerprint component: bind to the client User-Agent header. */
    public const FP_USER_AGENT = 'ua';

    /** Fingerprint component: bind to the client IP address. */
    public const FP_IP = 'ip';

    /** @var list<string> Active fingerprint components (subset of FP_* constants). */
    public array $fingerprint;

    /**
     * @param list<string> $fingerprint Components to bind: any of FP_USER_AGENT,
     *                                   FP_IP. Empty disables fingerprint binding.
     */
    public function __construct(
        /** Authenticated encrypter; when null the HMAC signing path is used. */
        public ?EncryptionPort $encrypter = null,
        /** Key for HMAC signing when no encrypter is bound (e.g. APP_KEY). */
        public string $signingKey = '',
        /** Absolute max age in seconds — the session dies this long after issue. */
        public int $lifetime = 7200,
        /** Idle timeout in seconds (0 = disabled) — dies after inactivity. */
        public int $idleTimeout = 0,
        /** Deflate the data when its serialized size exceeds this (0 = never). */
        public int $compressThreshold = 1024,
        /** Reject (drop) an outgoing cookie larger than this many bytes. */
        public int $maxBytes = 3800,
        /** Throw at build time unless authenticated (encrypter or signing key). */
        public bool $requireAuthentication = false,
        /** Throw at build time unless an EncryptionPort is bound (confidentiality). */
        public bool $requireEncryption = false,
        array $fingerprint = [],
    ) {
        // Normalise: keep only known components, de-duplicated, in a stable order.
        $this->fingerprint = array_values(array_intersect(
            [self::FP_USER_AGENT, self::FP_IP],
            array_map('strtolower', $fingerprint),
        ));
    }

    /** Whether the payload will be cryptographically protected at all. */
    public function isAuthenticated(): bool
    {
        return $this->encrypter !== null || $this->signingKey !== '';
    }

    /** Whether any client fingerprint component is bound. */
    public function bindsFingerprint(): bool
    {
        return $this->fingerprint !== [];
    }

    /** Whether the session cookie is confidential (encrypted, not just signed). */
    public function isEncrypted(): bool
    {
        return $this->encrypter !== null;
    }
}
