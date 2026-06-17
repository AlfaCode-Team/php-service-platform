<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class CreateEditionDTO
{
    public function __construct(
        public string  $title,
        public string  $slug,
        public ?string $startDate,
        public ?string $endDate,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $title = trim((string) $request->input('title', ''));
        $slug  = trim((string) $request->input('slug', ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer.';
        }

        if ($slug === '') {
            $errors['slug'] = 'Slug is required.';
        } elseif (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            $errors['slug'] = 'Slug may only contain lowercase letters, numbers, and hyphens.';
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
            slug:      $slug,
            startDate: $startDate,
            endDate:   $endDate,
        );
    }
}
