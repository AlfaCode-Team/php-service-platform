<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Application\Auth\AuthManager;
use Plugins\Auth\Application\Auth\GuardAccessor;
use Plugins\Auth\Application\Auth\ModelUserProvider;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Infrastructure\Auth\Drivers\RequestDriver;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Application\Auth\PersonalAccessTokenFactory;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Tests\Unit\Plugins\Auth\Support\FakeAuthService;
use Tests\Unit\Plugins\Auth\Support\FakeSession;
use Tests\Unit\Plugins\Auth\Support\FakeUserService;
use Tests\Unit\Plugins\Auth\Support\RecordingDatabasePort;
use Tests\Unit\Plugins\User\Support\FakeHasher;

#[CoversClass(AuthManager::class)]
final class AuthManagerExtensionTest extends TestCase
{
    private const CONFIG = [
        'defaults'  => ['guard' => 'web', 'provider' => 'users'],
        'guards'    => ['web' => ['driver' => 'session', 'provider' => 'users']],
        'providers' => ['users' => ['driver' => 'model']],
    ];

    private FakeUserService $users;

    protected function setUp(): void
    {
        AuthManager::flushDriverCache();
        $this->users = new FakeUserService();
    }

    private function manager(Request $request): AuthManager
    {
        $provider = new ModelUserProvider($this->users);

        return (new AuthManager(
            self::CONFIG,
            fn(string $name) => $provider,
            new FakeSession(),
        ))->setRequest($request);
    }

    public function test_extend_registers_a_custom_guard_that_takes_precedence(): void
    {
        $this->users->seed('u9', 'x', 'x@example.com');
        $request = Request::build(method: 'GET', path: '/')
            ->withIdentity(new Identity('u9', '', [], [], 'jwt'));

        $manager = $this->manager($request);
        $manager->extend('web', function (?Request $req, string $name, array $config) use ($manager): GuardAccessor {
            // Swap the session driver for a request driver at runtime.
            return new GuardAccessor($name, new RequestDriver(), new GuardContext($manager->provider('users')), $req);
        });

        self::assertTrue($manager->guard('web')->check());
        self::assertSame('u9', $manager->guard('web')->id());
    }

    public function test_extend_provider_registers_a_custom_provider(): void
    {
        $called = false;
        $manager = $this->manager(Request::build(method: 'GET', path: '/'));
        $manager->extendProvider('ldap', function (string $name) use (&$called): ModelUserProvider {
            $called = true;
            return new ModelUserProvider($this->users, $name);
        });

        self::assertSame('ldap', $manager->provider('ldap')->name());
        self::assertTrue($called);
    }

    public function test_forget_guards_clears_the_cache(): void
    {
        $manager = $this->manager(Request::build(method: 'GET', path: '/'));
        $a = $manager->guard('web');
        $manager->forgetGuards();
        $b = $manager->guard('web');

        self::assertNotSame($a, $b);
    }

    public function test_user_resolver_returns_the_default_guard_user(): void
    {
        $manager  = $this->manager(Request::build(method: 'GET', path: '/'));
        $resolver = $manager->userResolver();

        self::assertInstanceOf(\Closure::class, $resolver);
        self::assertNull($resolver()); // no session/identity → guest
    }

    public function test_call_forwards_to_default_guard(): void
    {
        // guest() is not defined on AuthManager → __call forwards to guard()->guest().
        self::assertTrue($this->manager(Request::build(method: 'GET', path: '/'))->guest());
    }

    public function test_issue_token_delegates_to_auth_service(): void
    {
        $auth = new AuthService(
            tokens:    new PersonalAccessTokenRepository(new RecordingDatabasePort()),
            hasher:    new FakeHasher(),
            jwtSecret: 'a-test-secret-at-least-32-chars-long!!',
        );

        $manager = (new AuthManager(self::CONFIG, fn(string $n) => new ModelUserProvider($this->users), new FakeSession(), $auth))
            ->setRequest(Request::build(method: 'GET', path: '/'));

        $jwt = $manager->issueToken('u1', ['roles' => ['user']], 3600);
        self::assertNotEmpty($jwt);
        self::assertSame(3, substr_count($jwt, '.') + 1); // header.payload.signature
    }

    public function test_issue_token_without_auth_service_throws(): void
    {
        $this->expectExceptionMessage('cannot issue tokens');
        $this->manager(Request::build(method: 'GET', path: '/'))->issueToken('u1');
    }
}
