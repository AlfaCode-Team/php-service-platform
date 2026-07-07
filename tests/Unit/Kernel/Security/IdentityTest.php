<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Identity::class)]
final class IdentityTest extends TestCase
{
    public function test_has_role_is_strict_membership(): void
    {
        $id = new Identity('u1', 't1', ['admin', 'editor'], [], 'jwt');

        self::assertTrue($id->hasRole('admin'));
        self::assertTrue($id->hasRole('editor'));
        self::assertFalse($id->hasRole('viewer'));
        self::assertFalse($id->hasRole('ADMIN')); // case-sensitive
    }

    public function test_has_permission_matches_exact(): void
    {
        $id = new Identity('u1', 't1', [], ['invoice:create', 'invoice:read'], 'jwt');

        self::assertTrue($id->hasPermission('invoice:create'));
        self::assertFalse($id->hasPermission('invoice:delete'));
    }

    public function test_wildcard_permission_grants_everything(): void
    {
        $id = new Identity('u1', 't1', ['admin'], ['*'], 'jwt');

        self::assertTrue($id->hasPermission('anything:at:all'));
        self::assertTrue($id->hasPermission('invoice:delete'));
    }

    public function test_guest_has_empty_user_and_is_guest(): void
    {
        $guest = Identity::guest();

        self::assertTrue($guest->isGuest());
        self::assertSame('', $guest->userId);
        self::assertSame('none', $guest->tokenType);
        self::assertFalse($guest->hasPermission('*'));
    }

    public function test_authenticated_user_is_not_guest(): void
    {
        self::assertFalse(Identity::asUser('u1')->isGuest());
    }

    public function test_as_user_factory_defaults(): void
    {
        $id = Identity::asUser('user-9');

        self::assertSame('user-9', $id->userId);
        self::assertSame('tenant-1', $id->tenantId);
        self::assertSame(['user'], $id->roles);
        self::assertSame('jwt', $id->tokenType);
    }

    public function test_as_admin_factory_has_wildcard_and_admin_role(): void
    {
        $admin = Identity::asAdmin('tenant-x');

        self::assertTrue($admin->hasRole('admin'));
        self::assertTrue($admin->hasPermission('any:permission'));
        self::assertSame('tenant-x', $admin->tenantId);
    }
}
