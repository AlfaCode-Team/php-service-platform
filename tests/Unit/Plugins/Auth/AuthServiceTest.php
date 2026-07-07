<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Auth\API\DTOs\TokenDTO;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Tests\Unit\Plugins\Auth\Support\FakeSession;
use Tests\Unit\Plugins\Auth\Support\RecordingDatabasePort;
use Tests\Unit\Plugins\User\Support\FakeHasher;

#[CoversClass(AuthService::class)]
final class AuthServiceTest extends TestCase
{
    private const SECRET = 'a-test-secret-at-least-32-chars-long!!';

    private RecordingDatabasePort $db;

    private function service(): AuthService
    {
        $this->db = new RecordingDatabasePort();

        return new AuthService(
            tokens:    new PersonalAccessTokenRepository($this->db),
            hasher:    new FakeHasher(),
            jwtSecret: self::SECRET,
            jwtAlgo:   'HS256',
            jwtIssuer: 'https://issuer.test',
            jwtAudience: 'https://api.test',
        );
    }

    public function test_issue_jwt_is_verifiable_and_carries_claims(): void
    {
        $jwt = $this->service()->issueJwt('user-1', [
            'roles'       => ['admin'],
            'permissions' => ['invoice:create'],
            'tnt'         => 'tenant-9',
        ], ttlSeconds: 3600);

        $claims = (array) JWT::decode($jwt, new Key(self::SECRET, 'HS256'));

        self::assertSame('user-1', $claims['sub']);
        self::assertSame('tenant-9', $claims['tnt']);
        self::assertSame('https://issuer.test', $claims['iss']);
        self::assertSame('https://api.test', $claims['aud']);
        self::assertSame(['admin'], (array) $claims['roles']);
        self::assertNotEmpty($claims['jti']);
        self::assertSame($claims['iat'] + 3600, $claims['exp']);
    }

    public function test_create_personal_access_token_returns_plaintext_and_stores_hash(): void
    {
        $result = $this->service()->createPersonalAccessToken('user-1', 'ci', ['read'], ttlSeconds: 60);

        // id.secret shape; id is the first dotted segment.
        self::assertArrayHasKey('token', $result);
        self::assertStringStartsWith($result['id'] . '.', $result['token']);

        // Exactly one INSERT, and the persisted hash is sha256(plaintext) — never the raw token.
        self::assertCount(1, $this->db->executed);
        $params = $this->db->executed[0]['params'];
        self::assertSame(hash('sha256', $result['token']), $params['token_hash']);
        self::assertStringNotContainsString($result['token'], (string) $params['token_hash']);
    }

    public function test_tokens_for_maps_rows_to_dtos(): void
    {
        $service = $this->service();
        $this->db->queryRows = [
            ['id' => 't1', 'name' => 'ci',  'abilities' => '["read"]', 'expires_at' => null, 'last_used_at' => null, 'created_at' => '2026-01-01T00:00:00+00:00'],
            ['id' => 't2', 'name' => 'cli', 'abilities' => '[]',       'expires_at' => null, 'last_used_at' => null, 'created_at' => '2026-01-02T00:00:00+00:00'],
        ];

        $tokens = $service->tokensFor('user-1');

        self::assertContainsOnlyInstancesOf(TokenDTO::class, $tokens);
        self::assertCount(2, $tokens);
        self::assertSame('t1', $tokens[0]->id);
        self::assertSame(['read'], $tokens[0]->abilities);
    }

    public function test_start_session_regenerates_id_and_stores_identity(): void
    {
        $session = new FakeSession();

        $this->service()->startSession($session, 'user-1', ['user'], ['read'], 'tenant-1');

        self::assertSame(1, $session->regenerations); // fixation defence
        self::assertSame('user-1', $session->get(AuthService::SESSION_USER));
        self::assertSame(['user'], $session->get(AuthService::SESSION_ROLES));
        self::assertSame(['read'], $session->get(AuthService::SESSION_PERMISSIONS));
        self::assertSame('tenant-1', $session->get(AuthService::SESSION_TENANT));
    }

    public function test_end_session_invalidates(): void
    {
        $session = new FakeSession();
        $service = $this->service();
        $service->startSession($session, 'user-1');

        $service->endSession($session);

        self::assertSame(1, $session->invalidations);
        self::assertNull($session->get(AuthService::SESSION_USER));
    }
}
