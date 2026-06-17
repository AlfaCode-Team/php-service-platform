<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

final readonly class LeaderboardDTO
{
    /** @param list<ContestantDTO> $contestants */
    public function __construct(
        public array $contestants,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {}

    public function toArray(): array
    {
        return [
            'contestants' => array_map(fn($c) => $c->toArray(), $this->contestants),
            'pagination' => [
                'total' => $this->total,
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total_pages' => $this->totalPages,
            ],
        ];
    }
}
