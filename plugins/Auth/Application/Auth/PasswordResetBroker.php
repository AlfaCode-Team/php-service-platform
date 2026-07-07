<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use Plugins\Auth\Application\Ports\PasswordBroker;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * PasswordResetBroker — CachePort-backed password reset broker.
 *
 * Reset tokens are stored as a SHA-256 hash keyed by the account email, with an
 * absolute TTL; only the plaintext token (returned once from sendResetLink) can
 * satisfy the check. Token comparison is constant-time. A throttle key prevents
 * rapid re-issuance. No token table needed — the cache is the store.
 */
final class PasswordResetBroker implements PasswordBroker
{
    private const TOKEN_PREFIX    = 'auth:pwreset:tok:';
    private const THROTTLE_PREFIX = 'auth:pwreset:thr:';

    public function __construct(
        private readonly UserServiceContract $users,
        private readonly CachePort $cache,
        private readonly int $ttlSeconds = 3600,
        private readonly int $throttleSeconds = 60,
    ) {}

    public function sendResetLink(string $email): array
    {
        $email = mb_strtolower(trim($email));

        $user = $this->users->findByIdentifier($email);
        if ($user === null) {
            return ['status' => self::INVALID_USER];
        }

        if ($this->cache->has(self::THROTTLE_PREFIX . $this->key($email))) {
            return ['status' => self::THROTTLED];
        }

        $token = bin2hex(random_bytes(32));
        $this->cache->set(self::TOKEN_PREFIX . $this->key($email), hash('sha256', $token), $this->ttlSeconds);
        $this->cache->set(self::THROTTLE_PREFIX . $this->key($email), 1, $this->throttleSeconds);

        return [
            'status' => self::RESET_LINK_SENT,
            'token'  => $token,
            'userId' => $user->id,
            'email'  => $email,
        ];
    }

    public function validateToken(string $email, string $token): bool
    {
        $email  = mb_strtolower(trim($email));
        $stored = $this->cache->get(self::TOKEN_PREFIX . $this->key($email));

        return is_string($stored) && $stored !== '' && hash_equals($stored, hash('sha256', $token));
    }

    public function reset(string $email, string $token, string $newPassword): string
    {
        $email = mb_strtolower(trim($email));

        if (!$this->validateToken($email, $token)) {
            return self::INVALID_TOKEN;
        }

        $user = $this->users->findByIdentifier($email);
        if ($user === null) {
            return self::INVALID_USER;
        }

        if (!$this->users->resetPassword($user->id, $newPassword)) {
            return self::INVALID_USER;
        }

        // One-time use: burn the token (and the throttle) on success.
        $this->cache->delete(self::TOKEN_PREFIX . $this->key($email));
        $this->cache->delete(self::THROTTLE_PREFIX . $this->key($email));

        return self::PASSWORD_RESET;
    }

    private function key(string $email): string
    {
        return hash('sha256', $email);
    }
}
