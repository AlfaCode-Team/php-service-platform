<?php

declare(strict_types=1);

namespace Plugins\Invoice;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Invoice\API\Contracts\InvoiceServiceContract;
use Plugins\Invoice\Application\Services\InvoiceService;
use Plugins\Invoice\Infrastructure\Persistence\InvoiceRepository;

final class Provider implements ModuleContract
{
    public function solves(): string { return 'invoice.management'; }

    /** @return list<class-string> */
    public function requires(): array { return [DatabasePort::class]; }

    /** @return list<class-string> */
    public function exposes(): array { return [InvoiceServiceContract::class]; }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(InvoiceRepository::class, static fn(ModuleContainer $c) =>
            new InvoiceRepository($c->make(DatabasePort::class), $c->make(Identity::class)));

        $container->bind(InvoiceServiceContract::class, static fn(ModuleContainer $c) =>
            new InvoiceService(
                repository:  $c->make(InvoiceRepository::class),
                transaction: $c->make(TransactionManager::class),
                collector:   $c->make(DomainEventCollector::class),
                eventBus:    $c->make(EventBus::class),
                identity:    $c->make(Identity::class),
            ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
