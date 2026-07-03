<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\InvitationStatus;
use Project\Support\Entity\Entity;

/**
 * Invitation — a view of one `tenant_invitations` row.
 *
 * The raw token is NEVER stored or carried here — only its SHA-256 hash. The
 * plaintext exists once, in the emailed link.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 *
 * Domain layer: zero external imports beyond Domain/ and the Project Entity base.
 */
final class Invitation extends Entity
{
    protected string $primaryKey = 'inviteId';

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $i = (new self())->forceFill([
            'inviteId'  => (string) $row['invite_id'],
            'tenantId'  => (string) $row['tenant_id'],
            'email'     => (string) $row['email'],
            'role'      => (string) $row['role'],
            'status'    => InvitationStatus::from((int) $row['status']),
            'expiresAt' => new \DateTimeImmutable((string) $row['expires_at']),
            'invitedBy' => (string) $row['invited_by'],
        ]);
        $i->syncOriginal();

        return $i;
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
