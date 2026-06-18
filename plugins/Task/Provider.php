<?php

declare(strict_types=1);

namespace Plugins\Task;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Task\API\Contracts\TaskServiceContract;
use Plugins\Task\Application\Services\TaskService;
use Plugins\Task\Infrastructure\Http\Stages\RequireJsonStage;
use Plugins\Task\Infrastructure\Persistence\TaskRepository;
use Plugins\View\API\Contracts\ViewRendererContract;

final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'task.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [DatabasePort::class,ViewRendererContract::class];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [TaskServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(TaskRepository::class, static fn(ModuleContainer $c) =>
            new TaskRepository(
                $c->make(DatabasePort::class),
                $c->make(Identity::class),
            )
        );

        $container->bind(TaskServiceContract::class, static fn(ModuleContainer $c) =>
            new TaskService(
                repository:  $c->make(TaskRepository::class),
                transaction: $c->make(TransactionManager::class),
                collector:   $c->make(DomainEventCollector::class),
                eventBus:    $c->make(EventBus::class),
                identity:    $c->make(Identity::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // A plugin can publish its OWN route-filter aliases. Routes in any
        // module.json / proj.json may then opt in with "filters": ["json"].
        $http->filter('json', RequireJsonStage::class);
    }
}
