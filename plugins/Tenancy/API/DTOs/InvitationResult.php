<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

/**
 * Result of creating an invitation. The raw `token` is returned EXACTLY ONCE —
 * only its hash is persisted. Embed it in the emailed accept link
 * (e.g. https://app.example.com/invite/accept?token=…); it cannot be recovered.
 */
final readonly class InvitationResult
{
    public function __construct(
        public string $inviteId,
        public string $tenantId,
        public string $email,
        public string $role,
        public string $token,
        public string $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'inviteId'  => $this->inviteId,
            'tenantId'  => $this->tenantId,
            'email'     => $this->email,
            'role'      => $this->role,
            'token'     => $this->token,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
