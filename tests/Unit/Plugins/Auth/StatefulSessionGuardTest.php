<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Application\Auth\ModelUserProvider;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Application\Auth\StatefulSessionGuard;
use Plugins\Auth\Infrastructure\Http\Stages\SessionAuthStage;
use Plugins\Cookie\Infrastructure\CookieJar;
use Tests\Unit\Plugins\Auth\Support\FakeSession;
use Tests\Unit\Plugins\Auth\Support\FakeUserService;

#[CoversClass(StatefulSessionGuard::class)]
final class StatefulSessionGuardTest extends TestCase
{
    private FakeUserService $users;
    private FakeSession $session;
    private CookieJar $cookies;

    protected function setUp(): void
    {
        $this->users   = new FakeUserService();
        $this->session = new FakeSession();
        $this->cookies = new CookieJar(); // no encrypter → recaller read/writes are plaintext
    }

    private function guard(?Request $request = null): StatefulSessionGuard
    {
        $guard = new StatefulSessionGuard(
            'web',
            new ModelUserProvider($this->users),
            $this->session,
            $this->users,
            $this->cookies,
        );

        return $guard->setRequest($request ?? Request::build(method: 'GET', path: '/'));
    }

    public function test_attempt_logs_in_on_valid_credentials(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $this->users->credentials['jane@example.com:pw'] = 'u1';

        $guard = $this->guard();
        self::assertTrue($guard->attempt(['email' => 'jane@example.com', 'password' => 'pw']));
        self::assertTrue($guard->check());
        self::assertSame('u1', $guard->id());
        self::assertSame('u1', $this->session->get(AuthService::SESSION_USER));
        self::assertGreaterThanOrEqual(1, $this->session->regenerations);
    }

    public function test_attempt_fails_on_bad_credentials_without_session(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');

        $guard = $this->guard();
        self::assertFalse($guard->attempt(['email' => 'jane@example.com', 'password' => 'wrong']));
        self::assertFalse($guard->check());
        self::assertNull($this->session->get(AuthService::SESSION_USER));
    }

    public function test_once_validates_without_writing_a_session(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $this->users->credentials['jane@example.com:pw'] = 'u1';

        $guard = $this->guard();
        self::assertTrue($guard->once(['email' => 'jane@example.com', 'password' => 'pw']));
        self::assertTrue($guard->check());               // set for this request
        self::assertNull($this->session->get(AuthService::SESSION_USER)); // but not persisted
    }

    public function test_login_using_id_and_logout(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $guard = $this->guard();

        self::assertNotFalse($guard->loginUsingId('u1'));
        self::assertSame('u1', $this->session->get(AuthService::SESSION_USER));

        $guard->logout();
        self::assertFalse($guard->check());
        self::assertNull($this->session->get(AuthService::SESSION_USER));
        self::assertSame(1, $this->session->invalidations);
    }

    public function test_remember_cookie_is_issued_and_resurrects_user(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');

        // Log in with remember → a recaller cookie is queued (token 'rotated').
        $this->guard()->loginUsingId('u1', remember: true);
        self::assertTrue($this->cookies->hasQueued(SessionAuthStage::RECALLER_COOKIE));

        // Fresh request/session, but the recaller cookie is present and valid.
        $this->session = new FakeSession();
        $this->users->rememberTokens['rotated'] = 'u1';
        $request = Request::build(
            method: 'GET',
            path: '/',
            cookies: [SessionAuthStage::RECALLER_COOKIE => 'u1|rotated'],
        );

        $guard = $this->guard($request);
        self::assertTrue($guard->check());
        self::assertSame('u1', $guard->id());
        self::assertTrue($guard->viaRemember());
    }

    public function test_basic_auth_logs_in_on_valid_header(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $this->users->credentials['jane@example.com:pw'] = 'u1';

        $header  = 'Basic ' . base64_encode('jane@example.com:pw');
        $request = Request::build(method: 'GET', path: '/', headers: ['Authorization' => $header]);

        $response = $this->guard($request)->basic('email');

        self::assertNull($response);                          // null → proceed
        self::assertSame('u1', $this->session->get(AuthService::SESSION_USER));
    }

    public function test_basic_auth_challenges_on_bad_header(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $header  = 'Basic ' . base64_encode('jane@example.com:wrong');
        $request = Request::build(method: 'GET', path: '/', headers: ['Authorization' => $header]);

        $response = $this->guard($request)->basic('email');

        self::assertNotNull($response);
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('Basic', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_logout_other_devices_reverifies_then_rotates(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $this->users->credentials['jane@example.com:pw'] = 'u1';

        $guard = $this->guard();
        $guard->loginUsingId('u1');

        self::assertNotNull($guard->logoutOtherDevices('pw'));
        // Wrong password → refused.
        self::assertNull($guard->logoutOtherDevices('nope'));
    }
}
