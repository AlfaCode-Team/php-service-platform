<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\ValueObjects;

/**
 * PKCE (RFC 7636) — Proof Key for Code Exchange.
 *
 * Verifies a `code_verifier` against the `code_challenge` recorded on the
 * authorization request. Supports both methods, but `S256` is REQUIRED for
 * public clients; `plain` is accepted only when the client explicitly used it.
 */
final class Pkce
{
    public const METHOD_PLAIN = 'plain';
    public const METHOD_S256  = 'S256';

    /** Whether a challenge method string is one we accept. */
    public static function supportsMethod(string $method): bool
    {
        return $method === self::METHOD_PLAIN || $method === self::METHOD_S256;
    }

    /**
     * Verify a verifier against the stored challenge. Constant-time where it
     * matters (hash_equals on the derived/literal challenge).
     */
    public static function verify(string $verifier, string $challenge, string $method): bool
    {
        // RFC 7636 §4.1 — verifier must be 43–128 chars from the unreserved set.
        $len = strlen($verifier);
        if ($len < 43 || $len > 128 || preg_match('/[^A-Za-z0-9\-._~]/', $verifier) === 1) {
            return false;
        }

        $computed = match ($method) {
            self::METHOD_S256  => self::base64UrlEncode(hash('sha256', $verifier, true)),
            self::METHOD_PLAIN => $verifier,
            default            => null,
        };

        return $computed !== null && hash_equals($challenge, $computed);
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
