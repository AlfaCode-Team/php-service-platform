<?php

declare(strict_types=1);

namespace Plugins\Voting\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

final readonly class AddContestantDTO
{
    public function __construct(
        public string $fullName,
        public string $slug,
        public string $avatarId,
        public string $detail,
        public string $categoryId,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $fullName = trim((string) $request->input('full_name', ''));
        $slug     = trim((string) $request->input('slug', ''));

        $errors = [];
        if ($fullName === '') {
            $errors['full_name'] = 'Full name is required.';
        } elseif (mb_strlen($fullName) > 255) {
            $errors['full_name'] = 'Full name must be 255 characters or fewer.';
        }

        if ($slug === '') {
            $errors['slug'] = 'Slug is required.';
        } elseif (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
            $errors['slug'] = 'Slug may only contain lowercase letters, numbers, and hyphens.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $avatarId   = trim((string) $request->input('avatar_id', ''));
        $detail     = trim((string) $request->input('detail', ''));
        $categoryId = trim((string) $request->input('category_id', ''));

        return new self(
            fullName:   $fullName,
            slug:       $slug,
            avatarId:   $avatarId,
            detail:     $detail,
            categoryId: $categoryId,
        );
    }
}
