<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * ApprovalRepository — handles migration approval database operations.
 *
 * Only this repository touches the database for approval requests.
 * MigrationApprovalManager NEVER touches DatabasePort directly.
 */
final class ApprovalRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /**
     * Create an approval request.
     *
     * @throws ServiceException
     */
    public function createApprovalRequest(
        string $approvalId,
        array $migrations,
        string $requester,
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO migration_approvals (id, migrations, requester, status, created_at) VALUES (?, ?, ?, ?, NOW())',
                [
                    $approvalId,
                    json_encode($migrations),
                    $requester,
                    'pending',
                ]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to create approval request: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get a specific approval request.
     */
    public function getApprovalRequest(string $approvalId): ?array
    {
        try {
            $record = $this->db->queryOne(
                'SELECT * FROM migration_approvals WHERE id = ?',
                [$approvalId]
            );

            if ($record && is_string($record['migrations'])) {
                $record['migrations'] = json_decode($record['migrations'], true);
            }

            return $record;
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to get approval request: {$e->getMessage()}"
            );
        }
    }

    /**
     * Approve a migration request.
     *
     * @throws ServiceException
     */
    public function approve(
        string $approvalId,
        string $approver,
        ?string $notes = null,
    ): void {
        try {
            $this->db->execute(
                'UPDATE migration_approvals SET status = ?, approver = ?, approved_at = NOW(), notes = ? WHERE id = ?',
                ['approved', $approver, $notes, $approvalId]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to approve migration: {$e->getMessage()}"
            );
        }
    }

    /**
     * Reject a migration request.
     *
     * @throws ServiceException
     */
    public function reject(
        string $approvalId,
        string $rejector,
        string $reason,
    ): void {
        try {
            $this->db->execute(
                'UPDATE migration_approvals SET status = ?, approver = ?, approved_at = NOW(), notes = ? WHERE id = ?',
                ['rejected', $rejector, $reason, $approvalId]
            );
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to reject migration: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get all pending approval requests.
     */
    public function getPendingApprovals(): array
    {
        try {
            $records = $this->db->query(
                'SELECT * FROM migration_approvals WHERE status = ? ORDER BY created_at DESC',
                ['pending']
            );

            return array_map(function ($record) {
                if (is_string($record['migrations'])) {
                    $record['migrations'] = json_decode($record['migrations'], true);
                }
                return $record;
            }, $records);
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to get pending approvals: {$e->getMessage()}"
            );
        }
    }

    /**
     * Check if there's a pending approval that hasn't timed out.
     */
    public function hasPendingApproval(int $timeoutSeconds = 3600): bool
    {
        try {
            $approval = $this->db->queryOne(
                'SELECT * FROM migration_approvals WHERE status = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)',
                ['pending', $timeoutSeconds]
            );

            return $approval !== null;
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to check pending approvals: {$e->getMessage()}"
            );
        }
    }
}
