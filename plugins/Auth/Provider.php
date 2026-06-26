<?php

declare(strict_types=1);

namespace Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;

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
        // Mirrors module.json "requires": database.management + crypto.services.
        // personal_access_tokens is a control-plane table, pinned to central.
        return [DatabaseConnectionManagerContract::class, HashingPort::class];
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
                jwtSecret: env('JWT_SECRET') ?: '',
                jwtAlgo:   env('JWT_ALGO') ?: 'HS256',
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // SecurityLayers are registered in the project bootstrap via
        // ->withSecurity([... new JwtAuthLayer(...) ...]); nothing to hook here.
    }
}
