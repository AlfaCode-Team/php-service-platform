<?php

declare(strict_types=1);

namespace Plugins\SocialAuth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\SocialAuth\API\Contracts\SocialAuthServiceContract;
use Plugins\SocialAuth\Application\Services\SocialAuthService;

/**
 * SocialAuth plugin — OAuth1/OAuth2 social login (ported Socialite engine).
 *
 * The Socialite engine lives untouched under Socialite/; this Provider wires
 * it into GDA by reading provider credentials from env and exposing only
 * SocialAuthServiceContract.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'auth.social';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SocialAuthServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(SocialAuthServiceContract::class, static function () {
            return new SocialAuthService(
                config: self::buildConfig(),
                baseUrl: env('SOCIAL_AUTH_BASE_URL') ?: '',
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No hooks: drivers are invoked from a controller/service on demand.
    }

    /**
     * Assemble the Socialite-shaped config from env vars.
     *
     * @return array<string,mixed>
     */
    private static function buildConfig(): array
    {
        $services = [];
        foreach (['github', 'google', 'facebook', 'gitlab', 'bitbucket', 'linkedin', 'slack', 'x'] as $driver) {
            $prefix = strtoupper($driver);
            $id = env("{$prefix}_CLIENT_ID");
            if ($id === false || $id === '') {
                continue;
            }
            $services[$driver] = [
                'client_id'     => $id,
                'client_secret' => env("{$prefix}_CLIENT_SECRET") ?: '',
                'redirect'      => env("{$prefix}_REDIRECT_URI") ?: '',
            ];
        }

        return ['services' => $services];
    }
}
