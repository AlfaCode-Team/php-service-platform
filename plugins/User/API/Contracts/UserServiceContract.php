<?php

declare(strict_types=1);

namespace Plugins\User\API\Contracts;

use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\API\DTOs\RegisterUserDTO;
use Plugins\User\API\DTOs\UpdateUserDTO;
use Plugins\User\API\DTOs\UserDTO;
use Plugins\User\API\DTOs\UserPage;
use Plugins\User\API\DTOs\VerifyEmailDTO;

/**
 * Published contract for the user.management domain. Other modules depend on
 * THIS — never on the concrete service, repository, or entities.
 */
interface UserServiceContract
{
    /** Keyset-paginated listing (admin-only). */
    public function list(ListUsersQuery $query): UserPage;

    public function register(RegisterUserDTO $dto): UserDTO;

    public function find(string $id): ?UserDTO;

    /** Apply a partial update. Returns null if no such (non-deleted) user. */
    public function update(string $id, UpdateUserDTO $dto): ?UserDTO;

    /** Confirm a user's email from a verification token. Null if no such user. */
    public function verifyEmail(string $id, VerifyEmailDTO $dto): ?UserDTO;

    /**
     * Verify a plaintext credential for a username/email. Returns the user on
     * success, null on any failure (unknown user, wrong password, inactive,
     * or temporarily locked out). Timing-safe and rate-limited.
     */
    public function verifyCredentials(string $identifier, string $password): ?UserDTO;

    public function delete(string $id): bool;
}
