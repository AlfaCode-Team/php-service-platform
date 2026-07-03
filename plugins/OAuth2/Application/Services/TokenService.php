<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\OAuth2\Application\Ports\AuthCodeStore;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Plugins\OAuth2\Application\Ports\ResourceOwnerVerifier;
use Plugins\OAuth2\Domain\Entities\DeviceCode;
use Plugins\OAuth2\Domain\Entities\Client;
use Plugins\OAuth2\Domain\Entities\RefreshToken;
use Plugins\OAuth2\Domain\Exceptions\OAuthException;
use Plugins\OAuth2\Domain\ValueObjects\GrantType;
use Plugins\OAuth2\Domain\ValueObjects\Pkce;

/**
 * TokenService — the /token endpoint (RFC 6749 §3.2).
 *
 * Dispatches the supported grants. Every grant authenticates the client first
 * (confidential clients by secret; public clients by client_id + PKCE), then
 * issues a JWT access token and, where applicable, a rotating refresh token.
 */
final class TokenService
{
    public function __construct(
        private readonly ClientStore $clients,
        private readonly AuthCodeStore $codes,
        private readonly RefreshTokenStore $refreshTokens,
        private readonly ScopeValidator $scopeValidator,
        private readonly TokenIssuer $issuer,
        private readonly HashingPort $hasher,
        private readonly ?ResourceOwnerVerifier $owners = null,
        private readonly int $refreshTtl = 1209600, // 14 days
        private readonly ?DeviceCodeStore $devices = null,
    ) {
    }

    /**
     * @param array<string,string> $params  POST body params.
     * @param array{0:string,1:string}|null $basic  [clientId, clientSecret] from Basic auth, if present.
     * @return array<string,mixed> the token response body.
     * @throws OAuthException
     */
    public function handle(array $params, ?array $basic): array
    {
        $grant = GrantType::tryFromString(trim($params['grant_type'] ?? ''));
        if ($grant === null) {
            throw OAuthException::unsupportedGrantType();
        }

        return match ($grant) {
            GrantType::AuthorizationCode => $this->authorizationCode($params, $basic),
            GrantType::ClientCredentials => $this->clientCredentials($params, $basic),
            GrantType::RefreshToken      => $this->refreshToken($params, $basic),
            GrantType::Password          => $this->password($params, $basic),
            GrantType::DeviceCode        => $this->deviceCode($params, $basic),
        };
    }

    // ── grants ────────────────────────────────────────────────────────────────

    private function authorizationCode(array $params, ?array $basic): array
    {
        $client = $this->authenticateClient($params, $basic, requireSecret: false);

        $rawCode = trim($params['code'] ?? '');
        if ($rawCode === '') {
            throw OAuthException::invalidRequest('Missing authorization code.');
        }

        $code = $this->codes->findByHash(hash('sha256', $rawCode));
        if ($code === null || $code->isExpired()) {
            throw OAuthException::invalidGrant('Authorization code is invalid or expired.');
        }
        if (!hash_equals($code->clientId, $client->id)) {
            throw OAuthException::invalidGrant('Authorization code was issued to another client.');
        }

        // Single-use: atomically consume. A losing race / replay → revoke any
        // tokens already minted from it would be ideal; at minimum reject.
        if (!$this->codes->consume($code->id)) {
            throw OAuthException::invalidGrant('Authorization code has already been used.');
        }

        // redirect_uri must match the one bound at /authorize.
        $redirectUri = trim($params['redirect_uri'] ?? '');
        if (!hash_equals($code->redirectUri, $redirectUri)) {
            throw OAuthException::invalidGrant('redirect_uri mismatch.');
        }

        // PKCE verification.
        if ($code->codeChallenge !== null) {
            $verifier = trim($params['code_verifier'] ?? '');
            if ($verifier === '' || !Pkce::verify($verifier, $code->codeChallenge, (string) $code->codeChallengeMethod)) {
                throw OAuthException::invalidGrant('PKCE verification failed.');
            }
        } elseif ($client->isPublic()) {
            throw OAuthException::invalidGrant('PKCE is required for public clients.');
        }

        return $this->issuePair($client, $code->userId, $code->scopes, nonce: $code->nonce);
    }

    private function clientCredentials(array $params, ?array $basic): array
    {
        $client = $this->authenticateClient($params, $basic, requireSecret: true);
        if (!$client->allowsGrant('client_credentials')) {
            throw OAuthException::unauthorizedClient();
        }

        $scopes = $this->scopeValidator->validate($params['scope'] ?? '', $client);

        // No refresh token for client_credentials (RFC 6749 §4.4.3). Subject = client.
        $access = $this->issuer->accessToken($client->id, $client->id, $scopes);

        return $this->response($access, null, $scopes);
    }

    private function refreshToken(array $params, ?array $basic): array
    {
        $client = $this->authenticateClient($params, $basic, requireSecret: false);

        $raw = trim($params['refresh_token'] ?? '');
        if ($raw === '') {
            throw OAuthException::invalidRequest('Missing refresh_token.');
        }

        $record = $this->refreshTokens->findByHash($this->issuer->hash($raw));
        if ($record === null) {
            throw OAuthException::invalidGrant('Refresh token is invalid.');
        }
        if (!hash_equals($record->clientId, $client->id)) {
            throw OAuthException::invalidGrant('Refresh token was issued to another client.');
        }

        // Reuse detection: a presented-but-already-revoked token means replay —
        // burn the whole family.
        if ($record->revoked || $record->isExpired()) {
            $this->refreshTokens->revokeFamily($record->familyId);
            throw OAuthException::invalidGrant('Refresh token is expired or has been revoked.');
        }
        if (!$this->refreshTokens->revokeIfActive($record->id)) {
            $this->refreshTokens->revokeFamily($record->familyId);
            throw OAuthException::invalidGrant('Refresh token reuse detected.');
        }

        // Narrowing scopes is allowed; widening is not.
        $scopes = $record->scopes;
        if (($params['scope'] ?? '') !== '') {
            $requested = $this->scopeValidator->validate($params['scope'], $client);
            foreach ($requested as $s) {
                if (!in_array($s, $record->scopes, true)) {
                    throw OAuthException::invalidScope('Cannot widen scope on refresh.');
                }
            }
            $scopes = $requested;
        }

        return $this->issuePair($client, $record->userId, $scopes, $record->familyId);
    }

    private function password(array $params, ?array $basic): array
    {
        $client = $this->authenticateClient($params, $basic, requireSecret: true);
        if (!$client->allowsGrant('password')) {
            throw OAuthException::unauthorizedClient();
        }
        if ($this->owners === null) {
            throw OAuthException::unsupportedGrantType('Password grant is not configured.');
        }

        $userId = $this->owners->verify(trim($params['username'] ?? ''), (string) ($params['password'] ?? ''));
        if ($userId === null) {
            throw OAuthException::invalidGrant('Invalid resource owner credentials.');
        }

        $scopes = $this->scopeValidator->validate($params['scope'] ?? '', $client);

        return $this->issuePair($client, $userId, $scopes);
    }

    /**
     * Device Authorization Grant — the device polls with its device_code (RFC 8628 §3.4).
     * Returns the standard polling errors (authorization_pending / slow_down /
     * access_denied / expired_token) until the user approves.
     */
    private function deviceCode(array $params, ?array $basic): array
    {
        if ($this->devices === null) {
            throw OAuthException::unsupportedGrantType('Device grant is not enabled.');
        }

        $client = $this->authenticateClient($params, $basic, requireSecret: false);

        $raw = trim($params['device_code'] ?? '');
        if ($raw === '') {
            throw OAuthException::invalidRequest('Missing device_code.');
        }

        $device = $this->devices->findByDeviceHash(hash('sha256', $raw));
        if ($device === null || !hash_equals($device->clientId, $client->id)) {
            throw OAuthException::invalidGrant('Unknown device_code.');
        }
        if ($device->isExpired()) {
            throw new OAuthException('expired_token', 'The device code has expired.', 400);
        }
        if ($device->status === DeviceCode::DENIED) {
            throw OAuthException::accessDenied('The user denied the request.');
        }

        if ($device->status === DeviceCode::PENDING) {
            // Enforce the minimum poll interval — too-fast polling gets slow_down.
            $now = new \DateTimeImmutable();
            if ($device->lastPolledAt !== null
                && ($now->getTimestamp() - $device->lastPolledAt->getTimestamp()) < $device->interval) {
                throw new OAuthException('slow_down', 'Polling too frequently.', 400);
            }
            $this->devices->markPolled($device->id, $now);

            throw new OAuthException('authorization_pending', 'The user has not yet approved the request.', 400);
        }

        // Authorized — consume so the access token is issued exactly once.
        if (!$this->devices->consume($device->id)) {
            throw OAuthException::invalidGrant('Device code already redeemed.');
        }

        return $this->issuePair($client, (string) $device->userId, $device->scopes);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Issue an access+refresh pair (and an OIDC id_token when `openid` is granted). */
    private function issuePair(Client $client, string $userId, array $scopes, ?string $familyId = null, ?string $nonce = null): array
    {
        $access  = $this->issuer->accessToken($userId, $client->id, $scopes);
        $rawRefresh = $this->issuer->refreshToken();

        $family = $familyId ?? bin2hex(random_bytes(16));
        $token  = RefreshToken::of(
            id:        bin2hex(random_bytes(16)),
            familyId:  $family,
            clientId:  $client->id,
            userId:    $userId,
            scopes:    $scopes,
            expiresAt: (new \DateTimeImmutable())->add(new \DateInterval('PT' . $this->refreshTtl . 'S')),
        );
        $this->refreshTokens->store($token, $this->issuer->hash($rawRefresh));

        // OpenID Connect: a granted `openid` scope yields an id_token.
        $idToken = null;
        if (in_array('openid', $scopes, true)) {
            // A public client cannot verify an HS-signed id_token (no shared
            // secret). Refuse rather than hand back an unverifiable token.
            if ($client->isPublic() && !$this->issuer->isAsymmetric()) {
                throw OAuthException::invalidRequest(
                    'OpenID Connect for public clients requires asymmetric (RS/ES/PS) token signing.'
                );
            }
            $idToken = $this->issuer->idToken($userId, $client->id, $nonce);
        }

        return $this->response($access, $rawRefresh, $scopes, $idToken);
    }

    /**
     * Authenticate the client. Confidential clients MUST present a valid secret;
     * public clients are identified by client_id only (PKCE secures the flow).
     */
    private function authenticateClient(array $params, ?array $basic, bool $requireSecret): Client
    {
        [$clientId, $clientSecret] = $basic ?? [trim($params['client_id'] ?? ''), $params['client_secret'] ?? null];

        if ((string) $clientId === '') {
            throw OAuthException::invalidClient('Missing client_id.');
        }

        $client = $this->clients->find((string) $clientId);
        if ($client === null || $client->revoked) {
            throw OAuthException::invalidClient();
        }

        if ($client->confidential) {
            if ($clientSecret === null || $clientSecret === '' || $client->secretHash === null
                || !$this->hasher->check((string) $clientSecret, $client->secretHash)) {
                throw OAuthException::invalidClient('Invalid client credentials.');
            }
        } elseif ($requireSecret) {
            // A public client cannot satisfy a grant that demands client auth.
            throw OAuthException::unauthorizedClient('This grant requires a confidential client.');
        }

        return $client;
    }

    /** @return array<string,mixed> */
    private function response(array $access, ?string $refresh, array $scopes, ?string $idToken = null): array
    {
        $body = [
            'token_type'   => 'Bearer',
            'access_token' => $access['token'],
            'expires_in'   => $access['expires_in'],
            'scope'        => implode(' ', $scopes),
        ];
        if ($refresh !== null) {
            $body['refresh_token'] = $refresh;
        }
        if ($idToken !== null) {
            $body['id_token'] = $idToken;
        }

        return $body;
    }
}
