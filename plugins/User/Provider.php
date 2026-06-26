<?php

declare(strict_types=1);

namespace Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\Application\Services\UserService;
use Plugins\User\Infrastructure\Audit\AuditLogger;
use Plugins\User\Infrastructure\Cli\RelayUserOutboxCommand;
use Plugins\User\Infrastructure\Http\Controllers\UserPageController;
use Plugins\User\Infrastructure\Outbox\OutboxWriter;
use Plugins\User\Infrastructure\Persistence\UserRepository;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * User plugin — owns the 'user.management' domain.
 *
 * Requires: database.management (the `users` table is the GLOBAL central
 * identity store — repository + outbox are pinned to the ConnectionManager
 * default/central connection so identity I/O always targets the central DB),
 * crypto.services (HashingPort), cache.redis
 * (CachePort, login lockout), view.rendering (HTML UI).
 * Publishes UserServiceContract for other modules (e.g. Auth).
 *
 * Reliable cross-module delivery uses a transactional outbox drained by the
 * `user:outbox:relay` CLI command (registered in boot()).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'user.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [
            DatabaseConnectionManagerContract::class,
            HashingPort::class,
            CachePort::class,
            ViewRendererContract::class,
        ];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [UserServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // `users`/`user_outbox` live in the CENTRAL database. Pin both to the
        // ConnectionManager default so identity I/O always targets the central
        // connection regardless of any per-request DatabasePort rebinding.
        $container->bindInternal(UserRepository::class, static fn(ModuleContainer $c) =>
            new UserRepository(self::central($c)));

        $container->bindInternal(OutboxWriter::class, static fn(ModuleContainer $c) =>
            new OutboxWriter(self::central($c)));

        $container->bindInternal(AuditLogger::class, static function (ModuleContainer $c) {
            $identity = $c->make(Identity::class);
            return new AuditLogger($identity->userId ?: null);
        });

        $container->bind(UserServiceContract::class, static fn(ModuleContainer $c) =>
            new UserService(
                repository:  $c->make(UserRepository::class),
                transaction: $c->make(TransactionManager::class),
                collector:   $c->make(DomainEventCollector::class),
                outbox:      $c->make(OutboxWriter::class),
                hasher:      $c->make(HashingPort::class),
                identity:    $c->make(Identity::class),
                cache:       $c->make(CachePort::class),
                audit:       $c->make(AuditLogger::class),
            ));

        // HTML page controller (renders the AJAX-driven UI shell).
        $container->bindInternal(UserPageController::class, static fn(ModuleContainer $c) =>
            new UserPageController(
                $c->make(ViewRendererContract::class),
            ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Outbox relay — resolved via CoreContainer autowiring (DatabasePort + EventBus).
        $cli->command(RelayUserOutboxCommand::class);
    }

    /**
     * The CENTRAL connection (always-connected DB that owns the global `users`
     * table) via the ConnectionManager default.
     */
    private static function central(ModuleContainer $c): DatabasePort
    {
        return $c->make(DatabaseConnectionManagerContract::class)->default();
    }
}
