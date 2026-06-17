<?php

namespace Plugins\SocialAuth\Socialite\Two;

use GuzzleHttp\RequestOptions;

class SlackOpenIdProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected $scopes = ['openid', 'email', 'profile'];

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://slack.com/openid/connect/authorize', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://slack.com/api/openid.connect.token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://slack.com/api/openid.connect.userInfo', [
            RequestOptions::HEADERS => ['Authorization' => 'Bearer '.$token],
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => array_get($user, 'sub'),
            'nickname' => null,
            'name' => array_get($user, 'name'),
            'email' => array_get($user, 'email'),
            'avatar' => array_get($user, 'picture'),
            'organization_id' => array_get($user, 'https://slack.com/team_id'),
        ]);
    }
}
