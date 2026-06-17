<?php

namespace Plugins\SocialAuth\Socialite\Two;

use GuzzleHttp\RequestOptions;

class LinkedInProvider extends AbstractProvider implements ProviderInterface
{
    /**
     * The scopes being requested.
     *
     * @var array
     */
    protected $scopes = ['r_liteprofile', 'r_emailaddress'];

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
        return $this->buildAuthUrlFromBase('https://www.linkedin.com/oauth/v2/authorization', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $basicProfile = $this->getBasicProfile($token);
        $emailAddress = $this->getEmailAddress($token);

        return array_merge($basicProfile, $emailAddress);
    }

    /**
     * Get the basic profile fields for the user.
     *
     * @param  string  $token
     * @return array
     */
    protected function getBasicProfile($token)
    {
        $fields = ['id', 'firstName', 'lastName', 'profilePicture(displayImage~:playableStreams)'];

        if (in_array('r_liteprofile', $this->getScopes())) {
            array_push($fields, 'vanityName');
        }

        $response = $this->getHttpClient()->get('https://api.linkedin.com/v2/me', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'projection' => '('.implode(',', $fields).')',
            ],
        ]);

        return (array) json_decode($response->getBody(), true);
    }

    /**
     * Get the email address for the user.
     *
     * @param  string  $token
     * @return array
     */
    protected function getEmailAddress($token)
    {
        $response = $this->getHttpClient()->get('https://api.linkedin.com/v2/emailAddress', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$token,
                'X-RestLi-Protocol-Version' => '2.0.0',
            ],
            RequestOptions::QUERY => [
                'q' => 'members',
                'projection' => '(elements*(handle~))',
            ],
        ]);

        return (array) array_get((array) json_decode($response->getBody(), true), 'elements.0.handle~');
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        $preferredLocale = array_get($user, 'firstName.preferredLocale.language').'_'.array_get($user, 'firstName.preferredLocale.country');
        $firstName = array_get($user, 'firstName.localized.'.$preferredLocale);
        $lastName = array_get($user, 'lastName.localized.'.$preferredLocale);

        $images = (array) array_get($user, 'profilePicture.displayImage~.elements', []);
        $avatar = array_first($images, function ($image) {
            return (
                $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['storageSize']['width'] ??
                $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['displaySize']['width']
            ) === 100;
        });
        $originalAvatar = array_first($images, function ($image) {
            return (
                $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['storageSize']['width'] ??
                $image['data']['com.linkedin.digitalmedia.mediaartifact.StillImage']['displaySize']['width']
            ) === 800;
        });

        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => null,
            'name' => $firstName.' '.$lastName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => array_get($user, 'emailAddress'),
            'avatar' => array_get($avatar, 'identifiers.0.identifier'),
            'avatar_original' => array_get($originalAvatar, 'identifiers.0.identifier'),
        ]);
    }
}
