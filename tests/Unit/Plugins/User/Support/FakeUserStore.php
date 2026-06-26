<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\Application\Ports\UserStore;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Exceptions\DuplicateUserException;

/** In-memory UserStore for service unit tests. */
final class FakeUserStore implements UserStore
{
    /** @var array<string, User> keyed by user_id */
    public array $byId = [];
    /** @var array<string,string> */
    public array $rehashed = [];

    public function paginate(ListUsersQuery $query): array
    {
        $all = array_values($this->byId);
        $hasMore = count($all) > $query->limit;
        return [array_slice($all, 0, $query->limit), $hasMore];
    }

    public function find(string $userId): ?User
    {
        return $this->byId[$userId] ?? null;
    }

    public function findByIdentifier(string $identifier): ?User
    {
        foreach ($this->byId as $u) {
            if ($u->username()->value() === $identifier
                || $u->email()->value() === mb_strtolower($identifier)) {
                return $u;
            }
        }
        return null;
    }

    public function existsByUsernameOrEmail(string $username, string $email, ?string $exceptUserId = null): bool
    {
        foreach ($this->byId as $u) {
            if ($u->id()->value() === $exceptUserId) {
                continue;
            }
            if ($u->username()->value() === $username || $u->email()->value() === $email) {
                return true;
            }
        }
        return false;
    }

    public function insert(User $user): void
    {
        if ($this->existsByUsernameOrEmail($user->username()->value(), $user->email()->value())) {
            throw new DuplicateUserException();
        }
        $this->byId[$user->id()->value()] = $user;
    }

    public function update(User $user): void
    {
        $this->byId[$user->id()->value()] = $user;
    }

    public function persistRehash(string $userId, string $passwordHash): void
    {
        $this->rehashed[$userId] = $passwordHash;
    }

    public function delete(string $userId): bool
    {
        if (!isset($this->byId[$userId])) {
            return false;
        }
        unset($this->byId[$userId]);
        return true;
    }
}
