<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;

final class DomainEventCollector
{
    /** @var list<DomainEventContract> */
    private array $pending = [];
    private bool $active = false;

    public function beginCollection(): void
    {
        $this->pending = [];
        $this->active = true;
    }

    public function collect(DomainEventContract $event): void
    {
        if (!$this->active) {
            throw new \LogicException(
                'Cannot collect domain events outside of a collection context. '
                . 'Call beginCollection() before the transaction begins.'
            );
        }
        $this->pending[] = $event;
    }

    /** @return list<DomainEventContract> */
    public function release(): array
    {
        $events = $this->pending;
        $this->pending = [];
        $this->active = false;
        return $events;
    }

    public function discard(): void
    {
        $this->pending = [];
        $this->active = false;
    }

    public function count(): int
    {
        return count($this->pending);
    }
}
