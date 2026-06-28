<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Domain\ValueObjects;

/**
 * The OAuth2 grant types this server supports (RFC 6749 + RFC 8628).
 *
 * Implicit is intentionally absent — it is deprecated by OAuth 2.1 (use
 * Authorization Code + PKCE for browser apps instead).
 */
enum GrantType: string
{
    case AuthorizationCode = 'authorization_code';
    case ClientCredentials = 'client_credentials';
    case RefreshToken      = 'refresh_token';
    case Password          = 'password';
    case DeviceCode        = 'urn:ietf:params:oauth:grant-type:device_code';

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
