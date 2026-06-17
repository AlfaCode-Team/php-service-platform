<?php

declare(strict_types=1);

namespace Plugins\Auth\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Contracts\SecurityLayerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityVerdict;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Stateless JWT authentication layer.
 *
 * Fills the kernel's intended "AuthModule layer" slot: the kernel ships no
 * token validator, so this plugin provides one. Wire it in a project bootstrap:
 *
 *   ->withSecurity([
 *       new FirewallLayer(...),
 *       new RateLimiterLayer(...),
 *       new JwtAuthLayer(secret: env('JWT_SECRET'), algo: 'HS256'),
 *   ])
 *
 * Behaviour:
 *   - No Authorization header        -> allow as guest (public routes still work)
 *   - Valid Bearer token             -> allow with a resolved Identity
 *   - Malformed / invalid / expired  -> deny(401)
 *
 * Never throws — always returns a SecurityVerdict (GDA security rule).
 */
final class JwtAuthLayer implements SecurityLayerContract
{
    public function __construct(
        private readonly string $secret,
        private readonly string $algo = 'HS256',
    ) {
    }

    public function check(Request $request): SecurityVerdict
    {
        $header = $request->header('Authorization') ?? '';
        if ($header === '' || !str_starts_with($header, 'Bearer ')) {
            // Anonymous request — let downstream authorization decide.
            return SecurityVerdict::allow($request);
        }

        $token = trim(substr($header, 7));
        if ($token === '' || $this->secret === '') {
            return SecurityVerdict::deny(401, 'Invalid or missing authentication token.');
        }

        try {
            $claims = (array) JWT::decode($token, new Key($this->secret, $this->algo));
        } catch (\Throwable) {
            return SecurityVerdict::deny(401, 'Authentication token is invalid or expired.');
        }

        $identity = new Identity(
            userId:      (string) ($claims['sub'] ?? ''),
            tenantId:    (string) ($claims['tenant'] ?? 'default'),
            roles:       array_values((array) ($claims['roles'] ?? [])),
            permissions: array_values((array) ($claims['permissions'] ?? [])),
            tokenType:   'jwt',
        );

        return SecurityVerdict::allow($request->withIdentity($identity));
    }
}
