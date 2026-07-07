<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\Guard;
use Plugins\Auth\API\ScopeInheritance;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

#[CoversClass(ScopeInheritance::class)]
final class ScopeInheritanceTest extends TestCase
{
    public function test_ancestor_scope_satisfies_descendant(): void
    {
        self::assertTrue(ScopeInheritance::satisfies(['admin'], 'admin:users:write'));
        self::assertTrue(ScopeInheritance::satisfies(['admin:users'], 'admin:users:write'));
        self::assertTrue(ScopeInheritance::satisfies(['admin:users:write'], 'admin:users:write'));
    }

    public function test_descendant_does_not_satisfy_ancestor(): void
    {
        self::assertFalse(ScopeInheritance::satisfies(['admin:users:write'], 'admin'));
        self::assertFalse(ScopeInheritance::satisfies(['admin:users'], 'admin:posts'));
        // Prefix that is not a colon-boundary must NOT match.
        self::assertFalse(ScopeInheritance::satisfies(['adm'], 'admin'));
    }

    public function test_namespaced_and_wildcard_forms(): void
    {
        self::assertTrue(ScopeInheritance::satisfies(['scope:admin'], 'admin:write'));
        self::assertTrue(ScopeInheritance::satisfies(['*'], 'anything:at:all'));
        self::assertFalse(ScopeInheritance::satisfies([], 'x'));
    }

    public function test_ancestors_expansion(): void
    {
        self::assertSame(['a', 'a:b', 'a:b:c'], ScopeInheritance::ancestors('a:b:c'));
        self::assertSame(['solo'], ScopeInheritance::ancestors('solo'));
    }

    public function test_guard_hasScope_is_hierarchical(): void
    {
        $guard = new Guard(new Identity('u1', '', [], ['reports'], 'jwt'));

        self::assertTrue($guard->hasScope('reports:export:csv'));
        self::assertFalse($guard->hasScope('billing'));
    }
}
