<?php

namespace Plugins\SocialAuth\Socialite\Ports;

use Plugins\SocialAuth\Socialite\Http\RedirectResponse;

// use Plugins\SocialAuth\Socialite\Http\RedirectResponse;

interface Provider
{
    /**
     * Redirect the user to the authentication page for the provider.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|RedirectResponse
     */
    public function redirect();

    /**
     * Get the User instance for the authenticated user.
     *
     * @return \Plugins\SocialAuth\Socialite\Ports\User
     */
    public function user();
}
