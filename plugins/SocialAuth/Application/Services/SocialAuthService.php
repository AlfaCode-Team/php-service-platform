<?php

declare(strict_types=1);

namespace Plugins\SocialAuth\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request as KernelRequest;
use Plugins\SocialAuth\API\Contracts\SocialAuthServiceContract;
use Plugins\SocialAuth\Socialite\Http\Request as SocialiteRequest;
use Plugins\SocialAuth\Socialite\Ports\User as SocialUser;
use Plugins\SocialAuth\Socialite\SocialiteManager;

/**
 * GDA service wrapping the ported Socialite engine.
 *
 * Builds a per-request SocialiteManager seeded with provider credentials and a
 * request factory so the stateful OAuth flow (CSRF state / PKCE) keeps working
 * inside the otherwise-stateless kernel.
 */
final class SocialAuthService implements SocialAuthServiceContract
{
    /**
     * @param array<string,mixed> $config provider credentials keyed under "services"
     */
    public function __construct(
        private readonly array $config,
        private readonly string $baseUrl,
    ) {
    }

    public function redirectUrl(string $driver): string
    {
        try {
            return $this->manager()->driver($driver)->redirect()->getTargetUrl();
        } catch (\Throwable $e) {
            throw new ServiceException(
                'social_auth.redirect.failed',
                layer: 'service.social_auth',
                context: ['driver' => $driver],
                previous: $e,
            );
        }
    }

    public function userFromCallback(string $driver, KernelRequest $request): SocialUser
    {
        try {
            $manager = $this->manager(static fn (): SocialiteRequest => SocialiteRequest::fromKernel($request));
            return $manager->driver($driver)->user();
        } catch (\Throwable $e) {
            throw new ServiceException(
                'social_auth.callback.failed',
                layer: 'service.social_auth',
                context: ['driver' => $driver],
                previous: $e,
            );
        }
    }

    private function manager(?callable $requestFactory = null): SocialiteManager
    {
        return new SocialiteManager($this->config, $requestFactory, $this->baseUrl);
    }
}
