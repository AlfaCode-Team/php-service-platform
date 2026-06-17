<?php

namespace Plugins\SocialAuth\Socialite;


use Plugins\SocialAuth\Socialite\Support\Manager;
use Plugins\SocialAuth\Socialite\Support\Config;
use Plugins\SocialAuth\Socialite\Http\Request;
use InvalidArgumentException;
use Plugins\SocialAuth\Socialite\Two\XProvider;
use League\OAuth1\Client\Server\Twitter as TwitterServer;
use Plugins\SocialAuth\Socialite\Two\SlackProvider;
use Plugins\SocialAuth\Socialite\Two\GithubProvider;
use Plugins\SocialAuth\Socialite\Two\GitlabProvider;
use Plugins\SocialAuth\Socialite\Two\GoogleProvider;
use Plugins\SocialAuth\Socialite\One\TwitterProvider;
use Plugins\SocialAuth\Socialite\Two\FacebookProvider;
use Plugins\SocialAuth\Socialite\Two\LinkedInProvider;
use Plugins\SocialAuth\Socialite\Two\BitbucketProvider;
use Plugins\SocialAuth\Socialite\Two\SlackOpenIdProvider;
use Plugins\SocialAuth\Socialite\Two\LinkedInOpenIdProvider;
use Plugins\SocialAuth\Socialite\Two\TwitterProvider as TwitterOAuth2Provider;

class SocialiteManager extends Manager implements Ports\Factory
{
    protected Config $config;

    /** @var \Closure(): Request */
    protected \Closure $requestFactory;

    protected string $baseUrl;

    /**
     * @param array<string,mixed> $config provider credentials keyed under "services"
     * @param (callable(): Request)|null $requestFactory builds the per-request wrapper
     */
    public function __construct(array $config = [], ?callable $requestFactory = null, string $baseUrl = '')
    {
        $this->config = new Config($config);
        $this->requestFactory = $requestFactory !== null
            ? \Closure::fromCallable($requestFactory)
            : static fn (): Request => new Request();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Get a driver instance.
     *
     * @param  string  $driver
     * @return mixed
     */
    public function with($driver)
    {
        return $this->driver($driver);
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createGithubDriver()
    {
        $config = $this->config->get('services.github');

        return $this->buildProvider(
            GithubProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createFacebookDriver()
    {
        $config = $this->config->get('services.facebook');

        return $this->buildProvider(
            FacebookProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createGoogleDriver()
    {
        $config = $this->config->get('services.google');

        return $this->buildProvider(
            GoogleProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createLinkedinDriver()
    {
        $config = $this->config->get('services.linkedin');

        return $this->buildProvider(
            LinkedInProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createLinkedinOpenidDriver()
    {
        $config = $this->config->get('services.linkedin-openid');

        return $this->buildProvider(
            LinkedInOpenIdProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createBitbucketDriver()
    {
        $config = $this->config->get('services.bitbucket');

        return $this->buildProvider(
            BitbucketProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createGitlabDriver()
    {
        $config = $this->config->get('services.gitlab');

        return $this->buildProvider(
            GitlabProvider::class, $config
        )->setHost($config['host'] ?? null);
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\One\AbstractProvider|\Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createTwitterDriver()
    {
        $config = $this->config->get('services.twitter');

        if (($config['oauth'] ?? null) === 2) {
            return $this->createTwitterOAuth2Driver();
        }

        return new TwitterProvider(
            ($this->requestFactory)(), new TwitterServer($this->formatConfig($config))
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createTwitterOAuth2Driver()
    {
        $config = $this->config->get('services.twitter') ?? $this->config->get('services.twitter-oauth-2');

        return $this->buildProvider(
            TwitterOAuth2Provider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createXDriver()
    {
        $config = $this->config->get('services.x') ?? $this->config->get('services.x-oauth-2');

        return $this->buildProvider(
            XProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createSlackDriver()
    {
        $config = $this->config->get('services.slack');

        return $this->buildProvider(
            SlackProvider::class, $config
        );
    }

    /**
     * Create an instance of the specified driver.
     *
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    protected function createSlackOpenidDriver()
    {
        $config = $this->config->get('services.slack-openid');

        return $this->buildProvider(
            SlackOpenIdProvider::class, $config
        );
    }

    /**
     * Build an OAuth 2 provider instance.
     *
     * @param  string  $provider
     * @param  array  $config
     * @return \Plugins\SocialAuth\Socialite\Two\AbstractProvider
     */
    public function buildProvider($provider, $config)
    {
        return new $provider(
            ($this->requestFactory)(), $config['client_id'],
            $config['client_secret'], $this->formatRedirectUrl($config),
            array_get($config, 'guzzle', [])
        );
    }

    /**
     * Format the server configuration.
     *
     * @param  array  $config
     * @return array
     */
    public function formatConfig(array $config)
    {
        return array_merge([
            'identifier' => $config['client_id'],
            'secret' => $config['client_secret'],
            'callback_uri' => $this->formatRedirectUrl($config),
        ], $config);
    }

    /**
     * Format the callback URL, resolving a relative URI if needed.
     *
     * @param  array  $config
     * @return string
     */
    protected function formatRedirectUrl(array $config)
    {
        $redirect = value($config['redirect']);

        return _str_starts_with($redirect ?? '', '/')
                    ? $this->baseUrl . $redirect
                    : $redirect;
    }

    /**
     * Forget all of the resolved driver instances.
     *
     * @return $this
     */
    public function forgetDrivers()
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * Get the default driver name.
     *
     * @return string
     *
     * @throws \InvalidArgumentException
     */
    public function getDefaultDriver()
    {
        throw new InvalidArgumentException('No Socialite driver was specified.');
    }
}
