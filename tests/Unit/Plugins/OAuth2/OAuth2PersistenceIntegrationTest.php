<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\OAuth2;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\OAuth2\Application\Services\AuthorizationService;
use Plugins\OAuth2\Application\Services\DeviceService;
use Plugins\OAuth2\Application\Services\ScopeValidator;
use Plugins\OAuth2\Application\Services\TokenIssuer;
use Plugins\OAuth2\Application\Services\TokenService;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Infrastructure\Persistence\AuthCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;
use Plugins\OAuth2\Infrastructure\Persistence\DeviceCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\RefreshTokenRepository;
use Plugins\OAuth2\Infrastructure\Persistence\ScopeRepository;

/**
 * Exercises the REAL repositories + services against an in-memory SQLite
 * DatabasePort — the layer the in-memory fakes can't cover (SQL dialect, rowcount
 * semantics of consume()/revokeIfActive(), JSON columns, boolean casts).
 */
final class OAuth2PersistenceIntegrationTest extends TestCase
{
    private const REDIRECT = 'https://app.example/callback';

    private MultiDriverDatabaseAdapter $db;
    private ClientRepository $clients;
    private AuthorizationService $authz;
    private TokenService $tokens;
    private DeviceService $deviceSvc;
    private DeviceCodeRepository $devices;

    protected function setUp(): void
    {
        $this->db = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));
        $this->createSchema();

        $hasher = new class implements HashingPort {
            public function make(string $value, array $options = []): string { return 'h:' . $value; }
            public function check(string $value, string $hashedValue): bool { return hash_equals($hashedValue, 'h:' . $value); }
            public function needsRehash(string $hashedValue, array $options = []): bool { return false; }
        };

        $this->clients = new ClientRepository($this->db);
        $codes         = new AuthCodeRepository($this->db);
        $refresh       = new RefreshTokenRepository($this->db);
        $this->devices = new DeviceCodeRepository($this->db);
        $scopes        = new ScopeRepository($this->db);
        $scopeV        = new ScopeValidator($scopes);
        $issuer        = new TokenIssuer('HS256', str_repeat('k', 64), null, 'https://issuer.test', null, 3600);

        $this->authz     = new AuthorizationService($this->clients, $codes, $scopeV, 60);
        $this->tokens    = new TokenService($this->clients, $codes, $refresh, $scopeV, $issuer, $hasher, null, 1209600, $this->devices);
        $this->deviceSvc = new DeviceService($this->clients, $this->devices, $scopeV, 600, 5);

        // Seed scopes + clients.
        foreach (['profile', 'openid', 'reports'] as $s) {
            $this->db->execute('INSERT INTO oauth_scopes (id) VALUES (:id)', ['id' => $s]);
        }
        $this->clients->create('public-spa', 'SPA', null, [self::REDIRECT], ['authorization_code', 'refresh_token'], ['profile'], false);
        $this->clients->create('device-tv', 'TV', null, [], ['urn:ietf:params:oauth:grant-type:device_code'], ['profile'], false);
    }

    public function test_authorization_code_flow_persists_and_is_single_use(): void
    {
        $verifier  = str_repeat('b', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $req = $this->authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'profile', 'state' => 's1', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);
        parse_str((string) parse_url($this->authz->issueCode($req, 'user-1'), PHP_URL_QUERY), $q);

        $resp = $this->tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ], null);

        self::assertNotEmpty($resp['access_token']);
        self::assertNotEmpty($resp['refresh_token']);

        // Single-use enforced by the real UPDATE ... WHERE consumed=0 rowcount.
        $this->expectException(OAuthException::class);
        $this->tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ], null);
    }

    public function test_refresh_rotation_reuse_detection_against_real_db(): void
    {
        $first   = $this->mintViaAuthCode();
        $refresh = $first['refresh_token'];

        $rotated = $this->tokens->handle([
            'grant_type' => 'refresh_token', 'client_id' => 'public-spa', 'refresh_token' => $refresh,
        ], null);
        self::assertNotSame($refresh, $rotated['refresh_token']);

        // Replay original → reuse detected (real revokeIfActive + revokeFamily).
        try {
            $this->tokens->handle(['grant_type' => 'refresh_token', 'client_id' => 'public-spa', 'refresh_token' => $refresh], null);
            self::fail('reuse should be rejected');
        } catch (OAuthException) {
        }

        // Descendant is now dead too (family burned).
        $this->expectException(OAuthException::class);
        $this->tokens->handle(['grant_type' => 'refresh_token', 'client_id' => 'public-spa', 'refresh_token' => $rotated['refresh_token']], null);
    }

    public function test_device_flow_against_real_db(): void
    {
        $auth = $this->deviceSvc->authorize(['client_id' => 'device-tv', 'scope' => 'profile'], ['device-tv', '']);

        try {
            $this->tokens->handle([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => 'device-tv', 'device_code' => $auth['device_code'],
            ], ['device-tv', '']);
            self::fail('expected authorization_pending');
        } catch (OAuthException $e) {
            self::assertSame('authorization_pending', $e->error);
        }

        $device = $this->devices->findByUserCode($auth['user_code']);
        self::assertNotNull($device);
        self::assertTrue($this->devices->authorize($device->id, 'user-7'));

        $resp = $this->tokens->handle([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => 'device-tv', 'device_code' => $auth['device_code'],
        ], ['device-tv', '']);
        self::assertNotEmpty($resp['access_token']);
    }

    public function test_client_crud_against_real_db(): void
    {
        $this->clients->create('c-new', 'New', 'h:sec', [self::REDIRECT], ['client_credentials'], ['reports'], true);

        $ids = array_map(static fn ($c) => $c->id, $this->clients->all());
        self::assertContains('c-new', $ids);

        self::assertTrue($this->clients->updateSecret('c-new', 'h:rotated'));
        self::assertSame('h:rotated', $this->clients->find('c-new')->secretHash);

        self::assertTrue($this->clients->revoke('c-new'));
        self::assertTrue($this->clients->find('c-new')->revoked);
    }

    public function test_prune_deletes_expired_rows(): void
    {
        // Insert an already-expired auth code directly, then prune.
        $this->db->execute(
            "INSERT INTO oauth_auth_codes (id, code_hash, client_id, user_id, redirect_uri, scopes, consumed, expires_at, created_at)
             VALUES ('x','xh','public-spa','u','" . self::REDIRECT . "','[]',0,'2000-01-01 00:00:00','2000-01-01 00:00:00')",
        );
        $codes = new AuthCodeRepository($this->db);

        self::assertSame(1, $codes->deleteExpired());
        self::assertSame(0, $codes->deleteExpired());
    }

    private function mintViaAuthCode(): array
    {
        $verifier  = str_repeat('b', 50);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $req = $this->authz->validate([
            'client_id' => 'public-spa', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'profile', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ]);
        parse_str((string) parse_url($this->authz->issueCode($req, 'user-1'), PHP_URL_QUERY), $q);

        return $this->tokens->handle([
            'grant_type' => 'authorization_code', 'client_id' => 'public-spa',
            'code' => $q['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => $verifier,
        ], null);
    }

    private function createSchema(): void
    {
        $this->db->execute('CREATE TABLE oauth_scopes (id TEXT PRIMARY KEY, description TEXT, created_at TEXT)');
        $this->db->execute(
            'CREATE TABLE oauth_clients (id TEXT PRIMARY KEY, name TEXT, secret_hash TEXT, redirect_uris TEXT,
             grant_types TEXT, scopes TEXT, confidential INTEGER, revoked INTEGER, owner_id TEXT, created_at TEXT)'
        );
        $this->db->execute(
            'CREATE TABLE oauth_auth_codes (id TEXT PRIMARY KEY, code_hash TEXT UNIQUE, client_id TEXT, user_id TEXT,
             redirect_uri TEXT, scopes TEXT, code_challenge TEXT, code_challenge_method TEXT, nonce TEXT,
             consumed INTEGER, expires_at TEXT, created_at TEXT)'
        );
        $this->db->execute(
            'CREATE TABLE oauth_refresh_tokens (id TEXT PRIMARY KEY, family_id TEXT, token_hash TEXT UNIQUE, client_id TEXT,
             user_id TEXT, scopes TEXT, revoked INTEGER, expires_at TEXT, created_at TEXT)'
        );
        $this->db->execute(
            'CREATE TABLE oauth_device_codes (id TEXT PRIMARY KEY, device_code_hash TEXT UNIQUE, user_code TEXT UNIQUE,
             client_id TEXT, scopes TEXT, status TEXT, user_id TEXT, interval_seconds INTEGER, last_polled_at TEXT,
             expires_at TEXT, created_at TEXT)'
        );
    }
}
