<?php

declare(strict_types=1);

namespace Plugins\Auth\Support;

/**
 * Token — dependency-free helpers for opaque secrets and ULIDs used by the
 * refresh-token session store. Secrets are stored ONLY as their SHA-256 hash;
 * the raw value is shown once.
 */
final class Token
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** A cryptographically-random opaque token (hex). */
    public static function random(int $bytes = 32): string
    {
        return bin2hex(random_bytes(max(16, $bytes)));
    }

    /** Storage hash for a raw token — never store the raw value. */
    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** A 26-char Crockford base32 ULID (fits the char(31) id columns). */
    public static function ulid(): string
    {
        $ms = (int) (microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $rand = '';
        for ($i = 0; $i < 16; $i++) {
            $rand .= self::ALPHABET[random_int(0, 31)];
        }

        return $time . $rand;
    }
}
