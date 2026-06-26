<?php

declare(strict_types=1);

namespace Plugins\User\Application\Ports;

use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\Domain\Entities\User;

/**
 * Internal persistence port for the user.management domain (DIP seam).
 *
 * The Application service depends on THIS, not the concrete repository, so it
 * can be unit-tested with an in-memory fake. Not part of the public API —
 * other modules use UserServiceContract.
 */
interface UserStore
{
    /**
     * @return array{0: list<User>, 1: bool} [users, hasMore]
     */
    public function paginate(ListUsersQuery $query): array;

    public function find(string $userId): ?User;

    public function findByIdentifier(string $identifier): ?User;

    public function existsByUsernameOrEmail(string $username, string $email, ?string $exceptUserId = null): bool;

    public function insert(User $user): void;

    public function update(User $user): void;

    public function persistRehash(string $userId, string $passwordHash): void;

    public function delete(string $userId): bool;
}
