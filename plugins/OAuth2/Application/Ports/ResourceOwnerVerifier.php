<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

/**
 * ResourceOwnerVerifier — verifies username/password for the Password grant.
 *
 * Keeps OAuth2 decoupled from any specific identity module: the project binds an
 * implementation (e.g. backed by UserServiceContract). Returns the user id on
 * success, null on failure.
 */
interface ResourceOwnerVerifier
{
    public function verify(string $username, string $password): ?string;
}
