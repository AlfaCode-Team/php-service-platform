<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Project\Http\Controllers\ApiController;

/**
 * GET /oauth/jwks — JSON Web Key Set (RFC 7517).
 *
 * Publishes the RSA/EC public key(s) resource servers use to verify access-token
 * signatures, so verifiers never need the shared secret and keys can rotate by
 * `kid`. Empty for symmetric (HS*) deployments — there is no public key to share.
 */
final class JwksController extends ApiController
{
    public function __construct(
        private readonly string $algo,
        private readonly ?string $publicKey,
        private readonly ?string $keyId,
    ) {
    }

    public function keys(): Response
    {
        $keys = [];
        $jwk  = $this->publicKey !== null ? $this->toJwk($this->publicKey) : null;
        if ($jwk !== null) {
            $keys[] = $jwk;
        }

        return Response::json(['keys' => $keys])->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /** Convert an RSA public-key PEM to a JWK (RFC 7518 §6.3.1). */
    private function toJwk(string $pem): ?array
    {
        if (!function_exists('openssl_pkey_get_public')) {
            return null;
        }

        $resource = @openssl_pkey_get_public($pem);
        if ($resource === false) {
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false) {
            return null;
        }

        $kid = ($this->keyId !== null && $this->keyId !== '') ? $this->keyId : null;

        // RSA (RS*/PS*).
        if (isset($details['rsa']['n'], $details['rsa']['e'])) {
            return array_filter([
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => $this->algo,
                'kid' => $kid,
                'n'   => $this->base64Url($details['rsa']['n']),
                'e'   => $this->base64Url($details['rsa']['e']),
            ], static fn ($v) => $v !== null);
        }

        // EC (ES*).
        if (isset($details['ec']['x'], $details['ec']['y'], $details['ec']['curve_name'])) {
            $crv = self::CURVES[$details['ec']['curve_name']] ?? null;
            if ($crv === null) {
                return null;
            }

            return array_filter([
                'kty' => 'EC',
                'use' => 'sig',
                'alg' => $this->algo,
                'kid' => $kid,
                'crv' => $crv,
                'x'   => $this->base64Url($details['ec']['x']),
                'y'   => $this->base64Url($details['ec']['y']),
            ], static fn ($v) => $v !== null);
        }

        return null;
    }

    /** OpenSSL curve name → JWK `crv` (RFC 7518 §6.2.1.1). */
    private const CURVES = [
        'prime256v1' => 'P-256',
        'secp256r1'  => 'P-256',
        'secp384r1'  => 'P-384',
        'secp521r1'  => 'P-521',
    ];

    private function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
