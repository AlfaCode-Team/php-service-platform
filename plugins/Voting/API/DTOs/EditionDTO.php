<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use Plugins\Voting\Domain\Entities\Edition;

final readonly class EditionDTO
{
    public function __construct(
        public string  $id,
        public string  $title,
        public string  $slug,
        public string  $organiserId,
        public string  $status,
        public ?string $startDate,
        public ?string $endDate,
        public string  $createdAt,
    ) {}

    public static function fromEntity(Edition $edition): self
    {
        return new self(
            id:          $edition->id()->value(),
            title:       $edition->title(),
            slug:        $edition->slug(),
            organiserId: $edition->organiserId(),
            status:      $edition->status()->value,
            startDate:   $edition->startDate()?->format(\DateTimeInterface::RFC3339),
            endDate:     $edition->endDate()?->format(\DateTimeInterface::RFC3339),
            createdAt:   $edition->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'slug'         => $this->slug,
            'organiser_id' => $this->organiserId,
            'status'       => $this->status,
            'start_date'   => $this->startDate,
            'end_date'     => $this->endDate,
            'created_at'   => $this->createdAt,
        ];
    }
}
