<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\Infrastructure\Http\Controllers\TransientTokenController;
use Tests\Unit\Plugins\Auth\Support\FakeAuthService;

#[CoversClass(TransientTokenController::class)]
final class TransientTokenControllerTest extends TestCase
{
    private function controller(FakeAuthService $auth, Identity $identity): TransientTokenController
    {
        $request = Request::build(method: 'POST', path: '/auth/token/refresh')->withIdentity($identity);

        return (new TransientTokenController($auth))->setRequest($request);
    }

    public function test_session_user_gets_a_short_lived_token(): void
    {
        $response = $this->controller(new FakeAuthService(), new Identity('u1', '', ['user'], ['read'], 'session'))->refresh();

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true)['data'];
        self::assertSame('jwt', $body['access_token']);   // FakeAuthService::issueJwt
        self::assertSame('Bearer', $body['token_type']);
        self::assertSame(900, $body['expires_in']);
    }

    public function test_guest_is_rejected(): void
    {
        self::assertSame(401, $this->controller(new FakeAuthService(), Identity::guest())->refresh()->getStatusCode());
    }

    public function test_bearer_caller_cannot_mint_a_transient_token(): void
    {
        // A JWT/PAT caller (tokenType != 'session') must not refresh a transient token.
        $response = $this->controller(new FakeAuthService(), new Identity('u1', '', [], [], 'jwt'))->refresh();
        self::assertSame(401, $response->getStatusCode());
    }
}
