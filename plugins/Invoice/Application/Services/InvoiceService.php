<?php

declare(strict_types=1);

namespace Plugins\Invoice\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Invoice\API\Contracts\InvoiceServiceContract;
use Plugins\Invoice\Infrastructure\Persistence\InvoiceRepository;

final class InvoiceService implements InvoiceServiceContract
{
    public function __construct(
        private readonly InvoiceRepository    $repository,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus           $eventBus,
        private readonly Identity           $identity,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return $this->repository->all();
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        return $this->repository->find($id);
    }
}
