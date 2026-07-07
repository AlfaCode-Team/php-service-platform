<?php

declare(strict_types=1);

namespace Plugins\User\Domain\ValueObjects;

/**
 * Password policy — centralises credential strength rules so the API DTO and
 * any future admin/reset flow validate identically.
 *
 * Rules (enterprise baseline, all configurable):
 *   - length 8–72 bytes (72 = bcrypt's hard input limit; longer is silently
 *     truncated by bcrypt, so we reject it rather than create a false sense of
 *     entropy)
 *   - at least 3 of 4 character classes (lower, upper, digit, symbol)
 *   - not a trivial/common password
 *
 * NIST 800-63B favours length + breach-screening over arbitrary composition;
 * a real deployment should add a Have-I-Been-Pwned k-anonymity check in the
 * Gateway layer. This VO is the local, dependency-free floor.
 */
final readonly class PasswordPolicy
{
    private const MIN = 8;
    private const MAX = 72; // bcrypt input limit (bytes)

    /** A tiny built-in deny list; replace/extend with a breach API in production. */
    private const COMMON = [
        'password', 'password123', '123456789012', 'qwertyuiop12',
        'administrator', 'letmein12345', 'iloveyou1234',
    ];

    /**
     * Validate a plaintext password. Returns a field→message error array
     * (empty when the password is acceptable) — never throws, never logs the
     * value.
     *
     * @return array<string,string>
     */
    public static function validate(string $password): array
    {
        $bytes = strlen($password);

        if ($bytes < self::MIN) {
            return ['password' => 'Password must be at least ' . self::MIN . ' characters.'];
        }
        if ($bytes > self::MAX) {
            return ['password' => 'Password must be ' . self::MAX . ' bytes or fewer.'];
        }

        $classes =
            (int) (bool) preg_match('/[a-z]/', $password)
            + (int) (bool) preg_match('/[A-Z]/', $password)
            + (int) (bool) preg_match('/\d/', $password)
            + (int) (bool) preg_match('/[^A-Za-z0-9]/', $password);

        if ($classes < 3) {
            return ['password' => 'Use at least three of: lowercase, uppercase, digits, symbols.'];
        }

        if (in_array(mb_strtolower($password), self::COMMON, true)) {
            return ['password' => 'This password is too common.'];
        }

        return [];
    }
}
