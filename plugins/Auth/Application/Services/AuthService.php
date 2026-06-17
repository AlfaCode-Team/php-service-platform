<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Firebase\JWT\JWT;

/**
 * Issues authentication credentials (JWT + personal access tokens).
 *
 * Token verification is handled by the SecurityLayer classes; this service only
 * mints credentials. Personal access tokens are stored hashed; the plaintext is
 * returned to the caller exactly once.
 */
final class AuthService implements AuthServiceContract
{
    public function __construct(
        private readonly PersonalAccessTokenRepository $tokens,
        private readonly HashingPort $hasher,
        private readonly string $jwtSecret,
        private readonly string $jwtAlgo = 'HS256',
    ) {
    }

    public function issueJwt(string $userId, array $claims = [], int $ttlSeconds = 3600): string
    {
        if ($this->jwtSecret === '') {
            throw new ServiceException('auth.jwt.unconfigured', layer: 'service.auth');
        }

        $now = time();
        $payload = [
            'sub'         => $userId,
            'tenant'      => $claims['tenant'] ?? 'default',
            'roles'       => array_values($claims['roles'] ?? []),
            'permissions' => array_values($claims['permissions'] ?? []),
            'iat'         => $now,
            'exp'         => $now + max(1, $ttlSeconds),
        ];

        return JWT::encode($payload, $this->jwtSecret, $this->jwtAlgo);
    }

    public function createPersonalAccessToken(string $userId, string $name = 'default'): array
    {
        $id        = bin2hex(random_bytes(16));
        $plaintext = $id . '.' . bin2hex(random_bytes(32));
        $hash      = hash('sha256', $plaintext);

        $this->tokens->store($id, $userId, $name, $hash);

        return ['id' => $id, 'token' => $plaintext];
    }

    public function revokePersonalAccessToken(string $id): void
    {
        $this->tokens->delete($id);
    }

    public function hashPassword(string $plain): string
    {
        return $this->hasher->make($plain);
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        return $this->hasher->check($plain, $hash);
    }
}
