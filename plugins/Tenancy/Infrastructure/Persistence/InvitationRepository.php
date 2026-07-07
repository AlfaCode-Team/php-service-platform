<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\InvitationStore;
use Plugins\Tenancy\Domain\Entities\Invitation;
use Plugins\Tenancy\Domain\ValueObjects\InvitationStatus;

/**
 * InvitationRepository — central `tenant_invitations`. DatabasePort ONLY; the
 * injected port is the central (control-plane) connection.
 */
final class InvitationRepository implements InvitationStore
{
    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function create(
        string $inviteId,
        string $tenantId,
        string $email,
        string $role,
        string $tokenHash,
        string $invitedBy,
        \DateTimeImmutable $expiresAt,
    ): void {
        try {
            $this->central->execute(
                'INSERT INTO tenant_invitations
                    (invite_id, tenant_id, email, role, token_hash, invited_by, status, expires_at, created_at, updated_at)
                 VALUES (:iid, :tid, :email, :role, :hash, :by, :status, :exp, :now, :now)',
                [
                    'iid'   => $inviteId,
                    'tid'   => $tenantId,
                    'email' => $email,
                    'role'  => $role,
                    'hash'  => $tokenHash,
                    'by'    => $invitedBy,
                    'status' => InvitationStatus::Pending->value,
                    'exp'   => $expiresAt->format('Y-m-d H:i:s'),
                    'now'   => self::now(),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to create invitation.', layer: 'repository.tenancy', previous: $e);
        }
    }

    public function findByTokenHash(string $tokenHash): ?Invitation
    {
        try {
            $row = $this->central->queryOne(
                'SELECT invite_id, tenant_id, email, role, status, expires_at, invited_by
                   FROM tenant_invitations WHERE token_hash = :hash LIMIT 1',
                ['hash' => $tokenHash],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load invitation.', layer: 'repository.tenancy', previous: $e);
        }

        return $row === null ? null : Invitation::fromRow($row);
    }

    public function pendingExists(string $tenantId, string $email): bool
    {
        try {
            $row = $this->central->queryOne(
                'SELECT 1 AS hit FROM tenant_invitations
                  WHERE tenant_id = :tid AND email = :email AND status = :status LIMIT 1',
                ['tid' => $tenantId, 'email' => $email, 'status' => InvitationStatus::Pending->value],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to check invitation.', layer: 'repository.tenancy', previous: $e);
        }

        return $row !== null;
    }

    public function markAccepted(string $inviteId): void
    {
        $this->setStatus($inviteId, InvitationStatus::Accepted, acceptedAt: true);
    }

    public function markRevoked(string $inviteId): void
    {
        $this->setStatus($inviteId, InvitationStatus::Revoked);
    }

    private function setStatus(string $inviteId, InvitationStatus $status, bool $acceptedAt = false): void
    {
        $sql = 'UPDATE tenant_invitations SET status = :status, updated_at = :now'
             . ($acceptedAt ? ', accepted_at = :now' : '')
             . ' WHERE invite_id = :iid';
        try {
            $this->central->execute($sql, ['status' => $status->value, 'now' => self::now(), 'iid' => $inviteId]);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to update invitation.', layer: 'repository.tenancy', previous: $e);
        }
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
