<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\InvitationStatus;

/**
 * Invitation — an immutable view of one `tenant_invitations` row.
 *
 * The raw token is NEVER stored or carried here — only its SHA-256 hash. The
 * plaintext exists once, in the emailed link.
 *
 * Domain layer: zero external imports beyond Domain/.
 */
final readonly class Invitation
{
    public function __construct(
        public string $inviteId,
        public string $tenantId,
        public string $email,
        public string $role,
        public InvitationStatus $status,
        public \DateTimeImmutable $expiresAt,
        public string $invitedBy,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            inviteId:  (string) $row['invite_id'],
            tenantId:  (string) $row['tenant_id'],
            email:     (string) $row['email'],
            role:      (string) $row['role'],
            status:    InvitationStatus::from((int) $row['status']),
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            invitedBy: (string) $row['invited_by'],
        );
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        return ($now ?? new \DateTimeImmutable()) >= $this->expiresAt;
    }

    /** Acceptable only when pending AND not past expiry. */
    public function isAcceptable(?\DateTimeImmutable $now = null): bool
    {
        return $this->status->isPending() && !$this->isExpired($now);
    }
}
