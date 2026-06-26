<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\OptimisticLockException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\User\API\DTOs\ListUsersQuery;
use Plugins\User\Application\Ports\UserStore;
use Plugins\User\Domain\Entities\User;
use Plugins\User\Domain\Exceptions\DuplicateUserException;

/**
 * UserRepository — DatabasePort ONLY. The `users` table is owned by the plugin
 * migration; this class never creates or alters schema.
 *
 * `users` is the GLOBAL central identity table: identity is centralized and
 * username/email are globally unique. The injected DatabasePort is the CENTRAL
 * connection (pinned by the Provider via the ConnectionManager default) so
 * identity reads/writes always target the central database.
 *
 * Invariants:
 *   - Every query is parameterised (no interpolation).
 *   - Reads exclude soft-deleted rows (deleted_at IS NULL).
 *   - Writes are optimistic-locked on `version`; a stale write throws
 *     OptimisticLockException (→ HTTP 409) instead of silently clobbering.
 *   - Exception context carries IDs only — never raw email/username (no PII in
 *     logs).
 */
final class UserRepository implements UserStore
{
    private const TABLE = 'users';

    private const COLUMNS =
        'user_id, username, email, password_hash, remember_token,
         status, version, email_verified_at, created_at';

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /**
     * Keyset-paginated listing (stable, O(1) deep pages). Fetches one extra row
     * to compute hasMore without a COUNT.
     *
     * @return array{0: list<User>, 1: bool} [users, hasMore]
     */
    public function paginate(ListUsersQuery $query): array
    {
        $params = ['limit' => $query->limit + 1];
        $cursor = '';
        if ($query->after !== null) {
            // user_id DESC → fetch rows strictly "older" than the cursor.
            $cursor = ' AND user_id < :after';
            $params['after'] = $query->after;
        }

        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE deleted_at IS NULL' . $cursor . '
                 ORDER BY user_id DESC
                 LIMIT :limit',
                $params,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list users.', layer: 'repository.user', previous: $e);
        }

        $hasMore = count($rows) > $query->limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return [array_map(static fn(array $r): User => self::hydrate($r), $rows), $hasMore];
    }

    public function find(string $userId): ?User
    {
        $row = $this->fetchBy('user_id', $userId);
        return $row === null ? null : self::hydrate($row);
    }

    /** Look up by username OR email — used for credential verification/login. */
    public function findByIdentifier(string $identifier): ?User
    {
        try {
            $row = $this->db->queryOne(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE (username = :id OR email = :email)
                   AND deleted_at IS NULL
                 LIMIT 1',
                ['id' => $identifier, 'email' => mb_strtolower($identifier)],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load user by identifier.', layer: 'repository.user', previous: $e);
        }

        return $row === null ? null : self::hydrate($row);
    }

    public function existsByUsernameOrEmail(string $username, string $email, ?string $exceptUserId = null): bool
    {
        $sql = 'SELECT 1 AS hit FROM ' . self::TABLE . '
                WHERE (username = :username OR email = :email)
                  AND deleted_at IS NULL';
        $params = ['username' => $username, 'email' => $email];

        if ($exceptUserId !== null) {
            $sql .= ' AND user_id <> :except';
            $params['except'] = $exceptUserId;
        }

        try {
            $row = $this->db->queryOne($sql . ' LIMIT 1', $params);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to check user uniqueness.', layer: 'repository.user', previous: $e);
        }

        return $row !== null;
    }

    public function insert(User $user): void
    {
        $now = self::now();

        try {
            $this->db->execute(
                'INSERT INTO ' . self::TABLE . '
                    (user_id, username, email, password_hash, remember_token,
                     status, version, email_verified_at, created_at, updated_at)
                 VALUES
                    (:user_id, :username, :email, :password_hash, :remember_token,
                     :status, :version, :email_verified_at, :created_at, :updated_at)',
                [
                    'user_id'           => $user->id()->value(),
                    'username'          => $user->username()->value(),
                    'email'             => $user->email()->value(),
                    'password_hash'     => $user->passwordHash(),
                    'remember_token'    => $user->rememberToken(),
                    'status'            => $user->status()->value,
                    'version'           => $user->version(),
                    'email_verified_at' => self::fmt($user->emailVerifiedAt()),
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ],
            );
        } catch (\Throwable $e) {
            if (self::isUniqueViolation($e)) {
                throw new DuplicateUserException();
            }
            throw new RepositoryException(
                'Failed to insert user.',
                layer: 'repository.user',
                context: ['userId' => $user->id()->value()],
                previous: $e,
            );
        }
    }

    /**
     * Optimistic-locked update. The entity has already bumped its version (via
     * commitChanges); we write WHERE version = newVersion - 1 and require one
     * affected row.
     */
    public function update(User $user): void
    {
        $expected = $user->version() - 1;

        try {
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . ' SET
                    username          = :username,
                    email             = :email,
                    password_hash     = :password_hash,
                    remember_token    = :remember_token,
                    status            = :status,
                    email_verified_at = :email_verified_at,
                    version           = :version,
                    updated_at        = :updated_at
                 WHERE user_id = :user_id
                   AND version = :expected AND deleted_at IS NULL',
                [
                    'username'          => $user->username()->value(),
                    'email'             => $user->email()->value(),
                    'password_hash'     => $user->passwordHash(),
                    'remember_token'    => $user->rememberToken(),
                    'status'            => $user->status()->value,
                    'email_verified_at' => self::fmt($user->emailVerifiedAt()),
                    'version'           => $user->version(),
                    'updated_at'        => self::now(),
                    'user_id'           => $user->id()->value(),
                    'expected'          => $expected,
                ],
            );
        } catch (\Throwable $e) {
            if (self::isUniqueViolation($e)) {
                throw new DuplicateUserException();
            }
            throw new RepositoryException(
                'Failed to update user.',
                layer: 'repository.user',
                context: ['userId' => $user->id()->value()],
                previous: $e,
            );
        }

        if ($affected < 1) {
            throw new OptimisticLockException(
                'User was modified concurrently; reload and retry.',
                layer: 'repository.user',
                context: ['userId' => $user->id()->value(), 'expectedVersion' => $expected],
            );
        }
    }

    /**
     * Persist only a re-hashed password (rehash-on-login). No version bump, no
     * events — it is a transparent credential upgrade, not a domain change.
     */
    public function persistRehash(string $userId, string $passwordHash): void
    {
        try {
            $this->db->execute(
                'UPDATE ' . self::TABLE . ' SET password_hash = :hash
                 WHERE user_id = :user_id AND deleted_at IS NULL',
                ['hash' => $passwordHash, 'user_id' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to upgrade password hash.',
                layer: 'repository.user',
                context: ['userId' => $userId],
                previous: $e,
            );
        }
    }

    /** Soft delete — sets deleted_at, never removes the row. */
    public function delete(string $userId): bool
    {
        $now = self::now();

        try {
            $affected = $this->db->execute(
                'UPDATE ' . self::TABLE . '
                 SET deleted_at = :now, updated_at = :now
                 WHERE user_id = :user_id AND deleted_at IS NULL',
                ['now' => $now, 'user_id' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to delete user.',
                layer: 'repository.user',
                context: ['userId' => $userId],
                previous: $e,
            );
        }

        return $affected > 0;
    }

    /** @return array<string, mixed>|null */
    private function fetchBy(string $column, string $value): ?array
    {
        try {
            return $this->db->queryOne(
                'SELECT ' . self::COLUMNS . ' FROM ' . self::TABLE . '
                 WHERE ' . $column . ' = :value AND deleted_at IS NULL
                 LIMIT 1',
                ['value' => $value],
            );
        } catch (\Throwable $e) {
            // NB: no PII (the looked-up value) in the log context — only the column.
            throw new RepositoryException(
                'Failed to load user.',
                layer: 'repository.user',
                context: ['by' => $column],
                previous: $e,
            );
        }
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): User
    {
        return User::reconstitute(
            id:              (string) $row['user_id'],
            username:        (string) $row['username'],
            email:           (string) $row['email'],
            passwordHash:    (string) $row['password_hash'],
            status:          (int) $row['status'],
            rememberToken:   $row['remember_token'] !== null ? (string) $row['remember_token'] : null,
            version:         (int) $row['version'],
            emailVerifiedAt: $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null,
            createdAt:       (string) $row['created_at'],
        );
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function fmt(?\DateTimeImmutable $dt): ?string
    {
        return $dt?->format('Y-m-d H:i:s');
    }

    /** Detect a UNIQUE/duplicate-key violation across PDO drivers. */
    private static function isUniqueViolation(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof \PDOException) {
                // SQLSTATE 23000/23505 = integrity constraint / unique violation.
                $sqlState = is_array($cur->errorInfo ?? null) ? ($cur->errorInfo[0] ?? '') : (string) $cur->getCode();
                if (in_array($sqlState, ['23000', '23505'], true)) {
                    return true;
                }
            }
            $msg = strtolower($cur->getMessage());
            if (str_contains($msg, 'duplicate') || str_contains($msg, 'unique constraint')) {
                return true;
            }
        }
        return false;
    }
}
