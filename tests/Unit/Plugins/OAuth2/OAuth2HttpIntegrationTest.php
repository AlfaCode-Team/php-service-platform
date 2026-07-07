<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\OAuth2;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\OAuth2\Application\Services\AuthorizationService;
use Plugins\OAuth2\Application\Services\IntrospectionService;
use Plugins\OAuth2\Application\Services\ScopeValidator;
use Plugins\OAuth2\Application\Services\TokenIssuer;
use Plugins\OAuth2\Application\Services\TokenService;
use Plugins\OAuth2\Infrastructure\Http\Controllers\DiscoveryController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\IntrospectionController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\JwksController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\TokenController;
use Plugins\OAuth2\Infrastructure\Persistence\AuthCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;
use Plugins\OAuth2\Infrastructure\Persistence\DeviceCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\RefreshTokenRepository;
use Plugins\OAuth2\Infrastructure\Persistence\ScopeRepository;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Drives REAL kernel Request objects through the OAuth2 JSON controllers
 * (RS256), over a real SQLite DatabasePort. Covers the HTTP surface the
 * service/persistence tests don't: Basic-auth parsing, body parsing, RFC error
 * envelopes, no-store headers, JWKS, discovery, and end-to-end RS256 id_token
 * verification against the published public key.
 */
final class OAuth2HttpIntegrationTest extends TestCase
{
    private const REDIRECT = 'https://app.example/callback';
    private const ALGO     = 'RS256';

    private string $privateKey;
    private string $publicKey;
    private MultiDriverDatabaseAdapter $db;
    private AuthorizationService $authz;
    private TokenController $tokenController;
    private IntrospectionController $introspectController;
    private JwksController $jwksController;
    private DiscoveryController $discoveryController;

    protected function setUp(): void
    {
        // DiscoveryController advertises the configured signing algorithm via env.
        $_ENV['JWT_ALGO'] = self::ALGO;
        $_SERVER['JWT_ALGO'] = self::ALGO;

        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            self::markTestSkipped('OpenSSL RSA key generation unavailable.');
        }
        $priv = '';
        openssl_pkey_export($res, $priv);
        $this->privateKey = $priv;
        $this->publicKey = openssl_pkey_get_details($res)['key'];

        $this->db = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));
        $this->createSchema();

        $hasher = new class implements HashingPort {
            public function make(string $value, array $options = []): string { return 'h:' . $value; }
            public function check(string $value, string $hashedValue): bool { return hash_equals($hashedValue, 'h:' . $value); }
            public function needsRehash(string $hashedValue, array $options = []): bool { return false; }
        };

        $clients = new ClientRepository($this->db);
        $codes   = new AuthCodeRepository($this->db);
        $refresh = new RefreshTokenRepository($this->db);
        $devices = new DeviceCodeRepository($this->db);
        $scopes  = new ScopeRepository($this->db);
        $scopeV  = new ScopeValidator($scopes);
        $issuer  = new TokenIssuer(self::ALGO, '', $this->privateKey, 'https://issuer.test', 'kid-1', 3600, 'https://api.example');

        $this->authz          = new AuthorizationService($clients, $codes, $scopeV, 60);
        $tokenService         = new TokenService($clients, $codes, $refresh, $scopeV, $issuer, $hasher, null, 1209600, $devices);
        $introspection        = new IntrospectionService($refresh, $issuer, $this->publicKey, self::ALGO);

        $this->tokenController       = (new TokenController($tokenService));
        $this->introspectController  = (new IntrospectionController($introspection, $clients, $hasher));
        $this->jwksController        = (new JwksController(self::ALGO, $this->publicKey, 'kid-1'));
        $this->discoveryController   = (new DiscoveryController($scopes));

        foreach (['profile', 'openid', 'reports'] as $s) {
            $this->db->execute('INSERT INTO oauth_scopes (id) VALUES (:id)', ['id' => $s]);
        }
        $clients->create('web-app', 'Web', 'h:websecret', [self::REDIRECT], ['authorization_code', 'refresh_token'], ['profile', 'openid'], true);
        $clients->create('svc', 'Service', 'h:svcsecret', [], ['client_credentials'], ['reports'], true);
    }

    public function test_client_credentials_over_basic_auth_returns_token(): void
    {
        $resp = $this->drive($this->tokenController, 'issue', $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'scope' => 'reports',
        ], basic: ['svc', 'svcsecret']));

        self::assertSame(200, $resp->getStatusCode());
        self::assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
        $body = json_decode($resp->getContent(), true);
        self::assertSame('Bearer', $body['token_type']);
        self::assertNotEmpty($body['access_token']);

        // The access token verifies against the published public key (RS256).
        $claims = (array) JWT::decode($body['access_token'], new Key($this->publicKey, self::ALGO));
        self::assertSame('https://api.example', $claims['aud']);   // resource audience, not client
        self::assertSame('svc', $claims['azp']);
        self::assertSame(['scope:reports'], $claims['permissions']);
    }

    public function test_bad_client_secret_returns_401_with_www_authenticate(): void
    {
        $resp = $this->drive($this->tokenController, 'issue', $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
        ], basic: ['svc', 'wrong']));

        self::assertSame(401, $resp->getStatusCode());
        self::assertNotNull($resp->headers->get('WWW-Authenticate'));
        self::assertSame('invalid_client', json_decode($resp->getContent(), true)['error']);
    }

    public function test_authorization_code_exchange_issues_verifiable_id_token(): void
    {
        // Mint a code directly (the /authorize half needs a session/identity).
        $req = $this->authz->validate([
            'client_id' => 'web-app', 'redirect_uri' => self::REDIRECT, 'response_type' => 'code',
            'scope' => 'openid profile', 'nonce' => 'nonce-xyz',
        ]);
        parse_str((string) parse_url($this->authz->issueCode($req, 'user-42'), PHP_URL_QUERY), $q);

        $resp = $this->drive($this->tokenController, 'issue', $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => self::REDIRECT,
        ], basic: ['web-app', 'websecret']));

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        self::assertArrayHasKey('id_token', $body);

        // id_token verifies against the public key and carries the nonce + correct aud.
        $idClaims = (array) JWT::decode($body['id_token'], new Key($this->publicKey, self::ALGO));
        self::assertSame('user-42', $idClaims['sub']);
        self::assertSame('web-app', $idClaims['aud']);    // OIDC: id_token aud = client
        self::assertSame('nonce-xyz', $idClaims['nonce']);
    }

    public function test_introspection_reports_active_for_valid_token(): void
    {
        // Get an access token first.
        $token = json_decode($this->drive($this->tokenController, 'issue', $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'scope' => 'reports',
        ], basic: ['svc', 'svcsecret']))->getContent(), true)['access_token'];

        $resp = $this->drive($this->introspectController, 'introspect', $this->post('/oauth/introspect', [
            'token' => $token,
        ], basic: ['svc', 'svcsecret']));

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        self::assertTrue($body['active']);
        self::assertSame('access_token', $body['token_type']);
        self::assertSame('svc', $body['client_id']);
    }

    public function test_jwks_publishes_the_rsa_public_key(): void
    {
        $resp = $this->drive($this->jwksController, 'keys', $this->get('/oauth/jwks'));

        self::assertSame(200, $resp->getStatusCode());
        $jwks = json_decode($resp->getContent(), true);
        self::assertCount(1, $jwks['keys']);
        self::assertSame('RSA', $jwks['keys'][0]['kty']);
        self::assertSame('kid-1', $jwks['keys'][0]['kid']);
        self::assertNotEmpty($jwks['keys'][0]['n']);
        self::assertNotEmpty($jwks['keys'][0]['e']);
    }

    public function test_openid_discovery_document(): void
    {
        $resp = $this->drive($this->discoveryController, 'openidConfiguration', $this->get('/.well-known/openid-configuration'));

        self::assertSame(200, $resp->getStatusCode());
        $doc = json_decode($resp->getContent(), true);
        self::assertStringContainsString('/oauth/token', $doc['token_endpoint']);
        self::assertStringContainsString('/oauth/userinfo', $doc['userinfo_endpoint']);
        self::assertContains(self::ALGO, $doc['id_token_signing_alg_values_supported']);
        self::assertContains('openid', $doc['scopes_supported']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['JWT_ALGO'], $_SERVER['JWT_ALGO']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param object $controller a RequestAware controller */
    private function drive(object $controller, string $action, Request $request): object
    {
        $controller->setRequest($request);

        return $controller->{$action}();
    }

    /** @param array<string,string> $body @param array{0:string,1:string}|null $basic */
    private function post(string $path, array $body, ?array $basic = null): Request
    {
        $server = ['HTTP_HOST' => 'oauth.test'];
        if ($basic !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode($basic[0] . ':' . $basic[1]);
        }

        return Request::createFromBase(SymfonyRequest::create('http://oauth.test' . $path, 'POST', $body, [], [], $server));
    }

    private function get(string $path): Request
    {
        return Request::createFromBase(
            SymfonyRequest::create('http://oauth.test' . $path, 'GET', [], [], [], ['HTTP_HOST' => 'oauth.test'])
        );
    }

    private function createSchema(): void
    {
        $this->db->execute('CREATE TABLE oauth_scopes (id TEXT PRIMARY KEY, description TEXT, created_at TEXT)');
        $this->db->execute('CREATE TABLE oauth_clients (id TEXT PRIMARY KEY, name TEXT, secret_hash TEXT, redirect_uris TEXT, grant_types TEXT, scopes TEXT, confidential INTEGER, revoked INTEGER, owner_id TEXT, created_at TEXT)');
        $this->db->execute('CREATE TABLE oauth_auth_codes (id TEXT PRIMARY KEY, code_hash TEXT UNIQUE, client_id TEXT, user_id TEXT, redirect_uri TEXT, scopes TEXT, code_challenge TEXT, code_challenge_method TEXT, nonce TEXT, consumed INTEGER, expires_at TEXT, created_at TEXT)');
        $this->db->execute('CREATE TABLE oauth_refresh_tokens (id TEXT PRIMARY KEY, family_id TEXT, token_hash TEXT UNIQUE, client_id TEXT, user_id TEXT, scopes TEXT, revoked INTEGER, expires_at TEXT, created_at TEXT)');
        $this->db->execute('CREATE TABLE oauth_device_codes (id TEXT PRIMARY KEY, device_code_hash TEXT UNIQUE, user_code TEXT UNIQUE, client_id TEXT, scopes TEXT, status TEXT, user_id TEXT, interval_seconds INTEGER, last_polled_at TEXT, expires_at TEXT, created_at TEXT)');
    }
}
