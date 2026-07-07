<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\Guard;

#[CoversClass(Guard::class)]
final class GuardTest extends TestCase
{
    public function test_guest_identity_projects_as_unauthenticated(): void
    {
        $guard = new Guard(Identity::guest());

        self::assertFalse($guard->check());
        self::assertTrue($guard->guest());
        self::assertSame('', $guard->id());
        self::assertSame('none', $guard->via());
        self::assertFalse($guard->viaToken());
        self::assertFalse($guard->viaSession());
    }

    public function test_jwt_identity_is_a_token_guard(): void
    {
        $guard = new Guard(new Identity('u1', 't1', ['user'], [], 'jwt'));

        self::assertTrue($guard->check());
        self::assertSame('u1', $guard->id());
        self::assertSame('t1', $guard->tenantId());
        self::assertSame('jwt', $guard->via());
        self::assertTrue($guard->viaToken());
        self::assertFalse($guard->viaSession());
    }

    public function test_api_key_counts_as_token_but_not_session(): void
    {
        $guard = new Guard(new Identity('u1', '', [], [], 'api_key'));

        self::assertTrue($guard->viaToken());
        self::assertFalse($guard->viaSession());
    }

    public function test_session_identity_is_a_session_guard(): void
    {
        $guard = new Guard(new Identity('u1', '', [], [], 'session'));

        self::assertTrue($guard->viaSession());
        self::assertFalse($guard->viaToken());
    }

    public function test_roles_and_permissions_pass_through(): void
    {
        $guard = new Guard(new Identity('u1', '', ['admin'], ['invoice:create'], 'jwt'));

        self::assertTrue($guard->hasRole('admin'));
        self::assertFalse($guard->hasRole('user'));
        self::assertTrue($guard->hasPermission('invoice:create'));
        self::assertFalse($guard->hasPermission('invoice:delete'));
    }

    public function test_hasScope_accepts_bare_and_namespaced_oauth_scopes(): void
    {
        // First-party PAT ability (bare) and OAuth2 delegated scope (namespaced).
        $guard = new Guard(new Identity('u1', '', [], ['read', 'scope:write'], 'jwt'));

        self::assertTrue($guard->hasScope('read'));      // bare permission
        self::assertTrue($guard->hasScope('write'));     // scope: prefix matched
        self::assertFalse($guard->hasScope('delete'));
    }

    public function test_wildcard_permission_satisfies_any_scope(): void
    {
        $guard = new Guard(new Identity('admin', '', ['admin'], ['*'], 'jwt'));

        self::assertTrue($guard->hasScope('anything'));
        self::assertTrue($guard->hasPermission('anything'));
    }

    public function test_acting_as_builds_a_scoped_guard(): void
    {
        $guard = Guard::actingAs('u1', ['reports'], ['analyst']);

        self::assertTrue($guard->check());
        self::assertSame('u1', $guard->id());
        self::assertTrue($guard->hasRole('analyst'));
        self::assertTrue($guard->hasScope('reports:export')); // hierarchical
    }
}
