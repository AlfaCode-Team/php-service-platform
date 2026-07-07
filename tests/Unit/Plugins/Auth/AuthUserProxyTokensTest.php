<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\Application\Auth\AuthUserProxy;
use Plugins\User\API\DTOs\UserDTO;
use Tests\Unit\Plugins\Auth\Support\FakeAuthService;

#[CoversClass(AuthUserProxy::class)]
final class AuthUserProxyTokensTest extends TestCase
{
    private function dto(): UserDTO
    {
        return new UserDTO('u1', 'jane', 'jane@example.com', true, '2026-01-01T00:00:00+00:00');
    }

    public function test_tokens_lists_from_the_service(): void
    {
        $svc = new FakeAuthService();
        $svc->tokens = [TokenDTO::fromRow(['id' => 't1', 'name' => 'ci', 'abilities' => ['read']])];

        $proxy = AuthUserProxy::fromUser($this->dto(), tokensService: $svc);

        self::assertCount(1, $proxy->tokens());
        self::assertSame('t1', $proxy->tokens()[0]->id);
    }

    public function test_create_token_delegates_to_the_service(): void
    {
        $svc   = new FakeAuthService();
        $proxy = AuthUserProxy::fromUser($this->dto(), tokensService: $svc);

        $result = $proxy->createToken('deploy', ['deploy:run']);

        self::assertSame('deploy', $svc->minted[0]['name']);
        self::assertSame('u1', $svc->minted[0]['userId']);
        self::assertArrayHasKey('token', $result);
    }

    public function test_create_token_without_service_throws(): void
    {
        $proxy = AuthUserProxy::fromUser($this->dto());

        $this->expectException(\LogicException::class);
        $proxy->createToken('x');
    }

    public function test_token_can_falls_back_to_permissions_when_no_access_token(): void
    {
        $proxy = AuthUserProxy::fromUser($this->dto(), permissions: ['read', 'scope:write']);

        self::assertTrue($proxy->tokenCan('read'));
        self::assertTrue($proxy->tokenCan('write'));   // scope: namespaced
        self::assertFalse($proxy->tokenCan('delete'));
    }

    public function test_with_access_token_scopes_the_check_to_that_token(): void
    {
        $proxy = AuthUserProxy::fromUser($this->dto(), permissions: ['*'])
            ->withAccessToken(TokenDTO::fromRow(['id' => 't1', 'abilities' => ['read']]));

        self::assertNotNull($proxy->token());
        self::assertTrue($proxy->tokenCan('read'));
        // The explicit token restricts abilities even though permissions had '*'.
        self::assertFalse($proxy->tokenCan('write'));
    }
}
