<?php

namespace Plugins\SocialAuth\Socialite\Two;

use GuzzleHttp\RequestOptions;

class GoogleProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected $scopes = [
        'openid',
        'profile',
        'email',
    ];

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://accounts.google.com/o/oauth2/auth', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://www.googleapis.com/oauth2/v4/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get('https://www.googleapis.com/oauth2/v3/userinfo', [
            RequestOptions::QUERY => [
                'prettyPrint' => 'false',
            ],
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshToken($refreshToken)
    {
        $response = $this->getRefreshTokenResponse($refreshToken);

        return new Token(
            array_get($response, 'access_token'),
            array_get($response, 'refresh_token', $refreshToken),
            array_get($response, 'expires_in'),
            explode($this->scopeSeparator, array_get($response, 'scope', ''))
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        // Deprecated: Fields added to keep backwards compatibility in 4.0. These will be removed in 5.0
        $user['id'] = array_get($user, 'sub');
        $user['verified_email'] = array_get($user, 'email_verified');
        $user['link'] = array_get($user, 'profile');

        return (new User)->setRaw($user)->map([
            'id' => array_get($user, 'sub'),
            'nickname' => array_get($user, 'nickname'),
            'name' => array_get($user, 'name'),
            'email' => array_get($user, 'email'),
            'avatar' => $avatarUrl = array_get($user, 'picture'),
            'avatar_original' => $avatarUrl,
        ]);
    }
}
