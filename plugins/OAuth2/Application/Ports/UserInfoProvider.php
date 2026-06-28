<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Application\Ports;

/**
 * UserInfoProvider — supplies OIDC UserInfo claims for a subject, filtered by the
 * granted scopes (profile, email, …). The project binds an implementation backed
 * by its identity store; the default returns only `sub`.
 *
 * @phpstan-type Claims array<string,mixed>
 */
interface UserInfoProvider
{
    /**
     * @param list<string> $scopes
     * @return array<string,mixed> claims including at least `sub`
     */
    public function claims(string $userId, array $scopes): array;
}
