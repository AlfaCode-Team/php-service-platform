<?php

namespace Plugins\SocialAuth\Socialite\Ports;

interface Factory
{
    /**
     * Get an OAuth provider implementation.
     *
     * @param  string  $driver
     * @return \Plugins\SocialAuth\Socialite\Interfaces\Provider
     */
    public function driver($driver = null);
}
