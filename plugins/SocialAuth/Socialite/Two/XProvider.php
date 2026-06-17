<?php

namespace Plugins\SocialAuth\Socialite\Two;

use GuzzleHttp\RequestOptions;

class XProvider extends TwitterProvider
{
    /**
     * {@inheritdoc}
     */
    public function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://x.com/i/oauth2/authorize', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://api.x.com/2/oauth2/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://api.x.com/2/users/me', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer '.$token],
            RequestOptions::QUERY => ['user.fields' => 'profile_image_url'],
        ]);

        return array_get(json_decode($response->getBody(), true), 'data');
    }
}
