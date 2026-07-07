<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\DTOs\PersonalAccessTokenResult;
use Plugins\Auth\API\DTOs\TokenDTO;

/**
 * PersonalAccessTokenFactory — mints a PAT for a user and returns the one-time
 * PersonalAccessTokenResult. GDA-native port of the old __DEV__ factory: it
 * delegates persistence to AuthService (hash-only storage) rather than an ORM.
 */
final class PersonalAccessTokenFactory
{
    public function __construct(private readonly AuthServiceContract $auth) {}

    /**
     * @param list<string> $scopes token abilities
     */
    public function make(string $userId, string $name, array $scopes = [], ?int $ttlSeconds = null): PersonalAccessTokenResult
    {
        $created = $this->auth->createPersonalAccessToken($userId, $name, $scopes, $ttlSeconds);

        $token = new TokenDTO(
            id:         $created['id'],
            name:       $name,
            abilities:  array_values($scopes),
            expiresAt:  $ttlSeconds !== null ? (new \DateTimeImmutable())->add(new \DateInterval('PT' . $ttlSeconds . 'S')) : null,
            lastUsedAt: null,
            createdAt:  new \DateTimeImmutable(),
        );

        return new PersonalAccessTokenResult($created['token'], $token);
    }
}
