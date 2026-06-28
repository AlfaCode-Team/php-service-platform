<?php

declare(strict_types=1);

namespace Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Infrastructure\Http\Controllers\SessionAuthController;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * Auth plugin — GDA-native authentication.
 *
 * Credential ISSUANCE (login, token minting) lives in AuthService, exposed via
 * AuthServiceContract. Credential VERIFICATION lives in the SecurityLayer
 * classes (JwtAuthLayer, PersonalAccessTokenLayer) which a project wires into
 * the kernel's withSecurity([...]) chain — the kernel runs them before any
 * module loads, exactly where the GDA design expects the AuthModule layer.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'auth.identity';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        // Mirrors module.json "requires". personal_access_tokens is a control-plane
        // table, pinned to central. UserServiceContract verifies credentials for the
        // session login flow; SessionPort (essential) carries the web/AJAX session.
        return [
            DatabaseConnectionManagerContract::class,
            HashingPort::class,
            UserServiceContract::class,
        ];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [AuthServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(PersonalAccessTokenRepository::class, static fn(ModuleContainer $c) =>
            new PersonalAccessTokenRepository(
                // Central connection — tokens belong to the control plane, not a
                // tenant DB, so resolve the ConnectionManager default rather than
                // the per-request (tenant-rebound) DatabasePort.
                $c->make(DatabaseConnectionManagerContract::class)->default(),
                env('AUTH_PAT_TABLE') ?: 'personal_access_tokens',
            )
        );

        $container->bind(AuthServiceContract::class, static fn(ModuleContainer $c) =>
            new AuthService(
                tokens:    $c->make(PersonalAccessTokenRepository::class),
                hasher:    $c->make(HashingPort::class),
                jwtSecret:   env('JWT_SECRET') ?: '',
                jwtAlgo:     env('JWT_ALGO') ?: 'HS256',
                jwtIssuer:     env('JWT_ISSUER') ?: null,
                jwtAudience:   env('JWT_AUDIENCE') ?: null,
                cache:         $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                jwtPrivateKey: self::readKey(env('JWT_PRIVATE_KEY'), env('JWT_PRIVATE_KEY_FILE')),
                jwtKid:        env('JWT_KID') ?: null,
            )
        );

        // Session login/logout controller for web + AJAX. Credentials verified by
        // the User module; SessionPort (essential) carries the stateful session.
        $container->bindInternal(SessionAuthController::class, static fn(ModuleContainer $c) =>
            new SessionAuthController(
                $c->make(AuthServiceContract::class),
                $c->make(UserServiceContract::class),
                $c->make(SessionPort::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Token verification (JWT / PAT) runs in the SecurityGateway via project
        // ->withSecurity([...]). SESSION auth (web + AJAX) cannot run there — the
        // session is only opened at after.load — so attach a session Identity just
        // after StartSessionStage and before the route `auth` filter.
        $http->hook('after.load', \Plugins\Auth\Infrastructure\Http\Stages\SessionAuthStage::class, priority: \Plugins\Auth\Infrastructure\Http\Stages\SessionAuthStage::PRIORITY);

        // Control-plane maintenance command (auth:tokens:prune). Deferred so only
        // CLI processes pay for it; the repository pins to the central connection
        // (tokens are control-plane data, never tenant-scoped).
        $cli->defer(static function (CliPipeline $cli): void {
            $c = new ModuleContainer($cli->container());
            $c->setScope('database.management');
            (new \Plugins\Database\Provider())->register($c);

            $repository = new PersonalAccessTokenRepository(
                $c->make(DatabaseConnectionManagerContract::class)->default(),
                env('AUTH_PAT_TABLE') ?: 'personal_access_tokens',
            );

            $cli->command(new \Plugins\Auth\Infrastructure\Cli\PruneAccessTokensCommand($repository));
        });
    }

    /**
     * Resolve a PEM signing key from either an inline env value or a file path
     * (the file form is preferred in production — keys stay off the process
     * environment). Returns null when neither is configured (symmetric mode).
     */
    private static function readKey(mixed $inline, mixed $file): ?string
    {
        $file = is_string($file) ? trim($file) : '';
        if ($file !== '' && is_readable($file)) {
            $contents = file_get_contents($file);
            if ($contents !== false && trim($contents) !== '') {
                return $contents;
            }
        }

        $inline = is_string($inline) ? trim($inline) : '';

        // Allow literal "\n" escapes from single-line .env values.
        return $inline !== '' ? str_replace('\n', "\n", $inline) : null;
    }
}
