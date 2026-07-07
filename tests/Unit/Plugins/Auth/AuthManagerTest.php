<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Application\Auth\AuthManager;
use Plugins\Auth\Application\Auth\ModelUserProvider;
use Plugins\Auth\Application\Ports\UserProvider;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Infrastructure\Auth\Drivers\JwtDriver;
use Plugins\Auth\Infrastructure\Auth\Drivers\RequestDriver;
use Plugins\Auth\Infrastructure\Auth\Drivers\SessionDriver;
use Plugins\Auth\Infrastructure\Auth\Drivers\TokenDriver;
use Tests\Unit\Plugins\Auth\Support\FakeSession;
use Tests\Unit\Plugins\Auth\Support\FakeUserService;

#[CoversClass(AuthManager::class)]
final class AuthManagerTest extends TestCase
{
    private FakeUserService $users;
    private FakeSession $session;

    private const CONFIG = [
        'defaults'  => ['guard' => 'web', 'provider' => 'users'],
        'guards'    => [
            'web'     => ['driver' => 'session', 'provider' => 'users'],
            'api'     => ['driver' => 'token',   'provider' => 'users'],
            'jwt'     => ['driver' => 'jwt',     'provider' => 'users'],
            'request' => ['driver' => 'request', 'provider' => 'users'],
        ],
        'providers' => ['users' => ['driver' => 'model']],
    ];

    protected function setUp(): void
    {
        AuthManager::flushDriverCache();
        $this->users   = new FakeUserService();
        $this->session = new FakeSession();
    }

    private function manager(Request $request): AuthManager
    {
        $provider = new ModelUserProvider($this->users);

        return (new AuthManager(
            config:          self::CONFIG,
            providerFactory: fn(string $name): UserProvider => $provider,
            session:         $this->session,
        ))->setRequest($request);
    }

    private function request(?Identity $identity = null): Request
    {
        $r = Request::build(method: 'GET', path: '/dashboard');
        return $identity === null ? $r : $r->withIdentity($identity);
    }

    public function test_scan_discovers_every_driver_and_skips_the_trait(): void
    {
        $drivers = AuthManager::drivers();

        self::assertSame(SessionDriver::class, $drivers['session']);
        self::assertSame(JwtDriver::class, $drivers['jwt']);
        self::assertSame(TokenDriver::class, $drivers['token']);
        self::assertSame(RequestDriver::class, $drivers['request']);
        // ResolvesFromVerdict is a trait, not a GuardDriver — must not appear.
        self::assertCount(4, $drivers);
    }

    public function test_session_guard_resolves_user_from_session_store(): void
    {
        $this->users->seed('user-1', 'jane', 'jane@example.com');
        $this->session->put(AuthService::SESSION_USER, 'user-1');
        $this->session->put(AuthService::SESSION_ROLES, ['user']);
        $this->session->put(AuthService::SESSION_PERMISSIONS, ['read']);

        $guard = $this->manager($this->request())->guard('web');

        self::assertTrue($guard->check());
        self::assertSame('user-1', $guard->id());
        $identity = $guard->identity();
        self::assertSame('session', $identity->tokenType);
        self::assertTrue($identity->hasRole('user'));
        self::assertTrue($identity->hasPermission('read'));
    }

    public function test_jwt_guard_rehydrates_the_gateway_verdict(): void
    {
        $this->users->seed('user-2', 'bob', 'bob@example.com');
        $identity = new Identity('user-2', 'tenant-9', ['admin'], ['*'], 'jwt');

        $guard = $this->manager($this->request($identity))->guard('jwt');

        self::assertTrue($guard->check());
        $out = $guard->identity();
        self::assertSame('user-2', $out->userId);
        self::assertSame('tenant-9', $out->tenantId);
        self::assertSame('jwt', $out->tokenType);
        self::assertTrue($out->hasPermission('anything')); // wildcard carried over
    }

    public function test_token_guard_ignores_a_jwt_identity(): void
    {
        $this->users->seed('user-3', 'sue', 'sue@example.com');
        // A JWT-typed identity must NOT satisfy the api-token ('token') guard.
        $guard = $this->manager($this->request(new Identity('user-3', '', [], [], 'jwt')))->guard('api');

        self::assertFalse($guard->check());
        self::assertSame('', $guard->id());
    }

    public function test_default_guard_is_used_when_unnamed(): void
    {
        $this->users->seed('user-1', 'jane', 'jane@example.com');
        $this->session->put(AuthService::SESSION_USER, 'user-1');

        // No name → defaults.guard = 'web' (session).
        self::assertTrue($this->manager($this->request())->check());
    }

    public function test_provider_credentials_roundtrip(): void
    {
        $this->users->seed('user-9', 'kim', 'kim@example.com');
        $this->users->credentials['kim@example.com:secret'] = 'user-9';

        $provider = $this->manager($this->request())->provider('users');

        $ok = $provider->retrieveByCredentials(['email' => 'kim@example.com', 'password' => 'secret']);
        self::assertNotNull($ok);
        self::assertSame('user-9', $ok->getAuthIdentifier());

        $bad = $provider->retrieveByCredentials(['email' => 'kim@example.com', 'password' => 'wrong']);
        self::assertNull($bad);
    }

    public function test_unknown_guard_throws(): void
    {
        $this->expectExceptionMessage('Auth guard [ghost] is not defined');
        $this->manager($this->request())->guard('ghost');
    }
}
