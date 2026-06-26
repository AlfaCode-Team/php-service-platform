<?php

declare(strict_types=1);

namespace Plugins\User\API\DTOs;

use Plugins\User\Domain\Entities\User;

/**
 * Outward-facing representation of a user. Deliberately omits the password hash
 * and remember token — those credentials NEVER cross the API boundary.
 */
final readonly class UserDTO
{
    public function __construct(
        public string $id,
        public string $username,
        public string $email,
        public string $status,
        public bool $emailVerified,
        public string $createdAt,
    ) {}

    public static function fromEntity(User $user): self
    {
        return new self(
            id:            $user->id()->value(),
            username:      $user->username()->value(),
            email:         $user->email()->value(),
            status:        $user->status()->label(),
            emailVerified: $user->isEmailVerified(),
            createdAt:     $user->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'username'      => $this->username,
            'email'         => $this->email,
            'status'        => $this->status,
            'emailVerified' => $this->emailVerified,
            'createdAt'     => $this->createdAt,
        ];
    }
}
