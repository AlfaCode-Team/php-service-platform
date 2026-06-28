<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Identity;

use Plugins\OAuth2\Application\Ports\UserInfoProvider;

/**
 * Default UserInfo provider — returns only the subject identifier. Replace with a
 * project-specific provider (binding {@see UserInfoProvider}) to surface profile,
 * email, etc. based on the granted scopes.
 */
final class SubjectUserInfoProvider implements UserInfoProvider
{
    public function claims(string $userId, array $scopes): array
    {
        return ['sub' => $userId];
    }
}
