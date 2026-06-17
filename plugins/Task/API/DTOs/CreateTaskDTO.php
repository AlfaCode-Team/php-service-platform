<?php

declare(strict_types=1);

namespace Plugins\Task\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class CreateTaskDTO
{
    public function __construct(
        public string $title,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $title = trim((string) $request->input('title', ''));

        $errors = [];
        if ($title === '') {
            $errors['title'] = 'Title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Title must be 255 characters or fewer.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(title: $title);
    }
}
