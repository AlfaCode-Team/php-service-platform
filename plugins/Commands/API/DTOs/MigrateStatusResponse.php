<?php

declare(strict_types=1);

namespace Plugins\Commands\API\DTOs;

final readonly class MigrateStatusResponse
{
    public function __construct(
        public array $applied = [],      // Applied migrations
        public array $pending = [],      // Not yet applied
        public int $appliedCount = 0,
        public int $pendingCount = 0,
    ) {}

    public static function fromMigrations(array $applied, array $pending): self
    {
        return new self(
            applied: $applied,
            pending: $pending,
            appliedCount: count($applied),
            pendingCount: count($pending),
        );
    }

    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'pending' => $this->pending,
            'applied_count' => $this->appliedCount,
            'pending_count' => $this->pendingCount,
        ];
    }
}
