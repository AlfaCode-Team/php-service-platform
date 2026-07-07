<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Application\Auth\PasswordResetBroker;
use Plugins\Auth\Application\Ports\PasswordBroker;
use Tests\Unit\Plugins\Auth\Support\FakeUserService;
use Tests\Unit\Plugins\User\Support\FakeCache;

#[CoversClass(PasswordResetBroker::class)]
final class PasswordResetBrokerTest extends TestCase
{
    private FakeUserService $users;
    private FakeCache $cache;

    protected function setUp(): void
    {
        $this->users = new FakeUserService();
        $this->cache = new FakeCache();
    }

    private function broker(): PasswordResetBroker
    {
        return new PasswordResetBroker($this->users, $this->cache, ttlSeconds: 3600, throttleSeconds: 60);
    }

    public function test_send_reset_link_mints_token_for_known_user(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');

        $result = $this->broker()->sendResetLink('jane@example.com');

        self::assertSame(PasswordBroker::RESET_LINK_SENT, $result['status']);
        self::assertNotEmpty($result['token']);
        self::assertSame('u1', $result['userId']);
    }

    public function test_unknown_user_gets_no_token(): void
    {
        $result = $this->broker()->sendResetLink('nobody@example.com');

        self::assertSame(PasswordBroker::INVALID_USER, $result['status']);
        self::assertArrayNotHasKey('token', $result);
    }

    public function test_second_request_is_throttled(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $broker = $this->broker();

        $broker->sendResetLink('jane@example.com');
        self::assertSame(PasswordBroker::THROTTLED, $broker->sendResetLink('jane@example.com')['status']);
    }

    public function test_full_reset_roundtrip_consumes_token_and_sets_password(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $broker = $this->broker();

        $token = $broker->sendResetLink('jane@example.com')['token'];

        self::assertTrue($broker->validateToken('jane@example.com', $token));
        self::assertSame(PasswordBroker::PASSWORD_RESET, $broker->reset('jane@example.com', $token, 'N3wPassw0rd!'));
        self::assertSame('N3wPassw0rd!', $this->users->resetPasswords['u1']);

        // One-time use — the token is now dead.
        self::assertFalse($broker->validateToken('jane@example.com', $token));
        self::assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset('jane@example.com', $token, 'again'));
    }

    public function test_wrong_token_is_rejected(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $broker = $this->broker();
        $broker->sendResetLink('jane@example.com');

        self::assertFalse($broker->validateToken('jane@example.com', 'forged'));
        self::assertSame(PasswordBroker::INVALID_TOKEN, $broker->reset('jane@example.com', 'forged', 'x'));
    }

    public function test_email_is_normalised_case_insensitively(): void
    {
        $this->users->seed('u1', 'jane', 'jane@example.com');
        $broker = $this->broker();

        $token = $broker->sendResetLink('JANE@Example.com')['token'];
        self::assertTrue($broker->validateToken('jane@example.com', $token));
    }
}
