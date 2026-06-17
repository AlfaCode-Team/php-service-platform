<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class UpdateEditionDTO
{
    public function __construct(
        public string  $title,
        public ?string $startDate,
        public ?string $endDate,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $title = trim((string) $request->input('title', ''));

        $errors = [];
        if (mb_strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer.';
        }

        $startDate = trim((string) $request->input('start_date', '')) ?: null;
        $endDate   = trim((string) $request->input('end_date', ''))   ?: null;

        if ($startDate !== null) {
            try {
                new \DateTimeImmutable($startDate);
            } catch (\Throwable) {
                $errors['start_date'] = 'start_date must be a valid date.';
            }
        }

        if ($endDate !== null) {
            try {
                new \DateTimeImmutable($endDate);
            } catch (\Throwable) {
                $errors['end_date'] = 'end_date must be a valid date.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(
            title:     $title,
            startDate: $startDate,
            endDate:   $endDate,
        );
    }
}
