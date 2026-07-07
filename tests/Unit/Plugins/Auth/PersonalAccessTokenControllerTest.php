<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\Infrastructure\Http\Controllers\PersonalAccessTokenController;
use Tests\Unit\Plugins\Auth\Support\FakeAuthService;

#[CoversClass(PersonalAccessTokenController::class)]
final class PersonalAccessTokenControllerTest extends TestCase
{
    private function controller(FakeAuthService $auth, ?Identity $identity): PersonalAccessTokenController
    {
        $request = Request::build(method: 'POST', path: '/auth/tokens', body: ['name' => 'ci', 'abilities' => ['read']]);
        if ($identity !== null) {
            $request = $request->withIdentity($identity);
        }

        return (new PersonalAccessTokenController($auth))->setRequest($request);
    }

    public function test_index_lists_callers_tokens(): void
    {
        $auth = new FakeAuthService();
        $auth->tokens = [TokenDTO::fromRow(['id' => 't1', 'name' => 'ci', 'abilities' => ['read']])];

        $response = $this->controller($auth, Identity::asUser('u1', ''))->index();

        self::assertSame(200, $response->getStatusCode());
    }

    public function test_guest_is_rejected(): void
    {
        $response = $this->controller(new FakeAuthService(), Identity::guest())->index();
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_store_mints_a_token_for_the_caller(): void
    {
        $auth = new FakeAuthService();
        $response = $this->controller($auth, Identity::asUser('u1', ''))->store();

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('u1', $auth->minted[0]['userId']);
        self::assertSame('ci', $auth->minted[0]['name']);
    }

    public function test_destroy_only_revokes_own_token(): void
    {
        $auth = new FakeAuthService();
        $auth->tokens = [TokenDTO::fromRow(['id' => 'mine', 'abilities' => []])];
        $ctrl = $this->controller($auth, Identity::asUser('u1', ''));

        self::assertSame(204, $ctrl->destroy('mine')->getStatusCode());
        self::assertSame(404, $ctrl->destroy('someone-elses')->getStatusCode());
    }
}
