<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security;

final readonly class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly string $tokenType,
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array('*', $this->permissions, true)
            || in_array($permission, $this->permissions, true);
    }

    public function isGuest(): bool
    {
        return empty($this->userId);
    }

    /** @param list<string> $roles @param list<string> $permissions */
    public static function asUser(
        string $userId,
        string $tenantId = 'tenant-1',
        array $roles = ['user'],
        array $permissions = [],
        string $tokenType = 'jwt',
    ): self {
        return new self($userId, $tenantId, $roles, $permissions, $tokenType);
    }

    /** @param list<string> $permissions */
    public static function asAdmin(
        string $tenantId = 'tenant-1',
        string $userId = 'admin-user',
        array $permissions = ['*'],
    ): self {
        return new self($userId, $tenantId, ['admin'], $permissions, 'jwt');
    }

    public static function guest(): self
    {
        return new self('', '', [], [], 'none');
    }
}
