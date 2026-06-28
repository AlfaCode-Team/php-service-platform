<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Identity;

use Plugins\OAuth2\Application\Ports\ResourceOwnerVerifier;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * Backs the OAuth2 Password grant with the User module's timing-safe,
 * rate-limited credential verification. Returns the user id on success.
 */
final class UserResourceOwnerVerifier implements ResourceOwnerVerifier
{
    public function __construct(private readonly UserServiceContract $users)
    {
    }

    public function verify(string $username, string $password): ?string
    {
        if ($username === '' || $password === '') {
            return null;
        }

        return $this->users->verifyCredentials($username, $password)?->id;
    }
}
