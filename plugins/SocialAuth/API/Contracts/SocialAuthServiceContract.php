<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\SocialAuth\Socialite\Ports\User as SocialUser;

/**
 * Published social-authentication contract.
 *
 * Other modules (e.g. an Auth module) depend on this to start an OAuth
 * redirect and to resolve the returning user. The Socialite engine stays
 * internal to this plugin.
 */
interface SocialAuthServiceContract
{
    /**
     * Build the provider authorization redirect URL for $driver
     * (e.g. 'github', 'google', 'facebook').
     */
    public function redirectUrl(string $driver): string;

    /**
     * Resolve the authenticated social user from the OAuth callback request.
     */
    public function userFromCallback(string $driver, Request $request): SocialUser;
}
