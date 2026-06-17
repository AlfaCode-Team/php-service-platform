<?php

declare(strict_types=1);

namespace Plugins\Commands\Approval;

use Plugins\Commands\Application\Services\CommandsInfrastructureService;

final class MigrationApprovalManager
{
    public function __construct(
        private readonly CommandsInfrastructureService $infrastructure,
    ) {}

    public function createApprovalRequest(array $pendingMigrations): ApprovalRequest
    {
        $id = $this->generateApprovalId();
        $requester = $this->getCurrentUser();

        $this->infrastructure->createApprovalRequest($id, $pendingMigrations, $requester);

        return new ApprovalRequest(
            id: $id,
            migrations: $pendingMigrations,
            requester: $requester,
            createdAt: date('Y-m-d H:i:s'),
            status: 'pending',
        );
    }

    public function getApprovalRequest(string $id): ?ApprovalRequest
    {
        $result = $this->infrastructure->getApprovalRequest($id);

        if (!$result) {
            return null;
        }

        return new ApprovalRequest(
            id: $result['id'],
            migrations: $result['migrations'],
            requester: $result['requester'],
            createdAt: $result['created_at'],
            status: $result['status'],
            approver: $result['approver'] ?? null,
            approvedAt: $result['approved_at'] ?? null,
            notes: $result['notes'] ?? null,
        );
    }

    public function approve(string $id, ?string $notes = null): void
    {
        $this->infrastructure->approveRequest($id, $this->getCurrentUser(), $notes);
    }

    public function reject(string $id, string $reason): void
    {
        $this->infrastructure->rejectRequest($id, $this->getCurrentUser(), $reason);
    }

    public function getPendingApprovals(): array
    {
        $results = $this->infrastructure->getPendingApprovalRequests();

        return array_map(
            fn($row) => new ApprovalRequest(
                id: $row['id'],
                migrations: $row['migrations'],
                requester: $row['requester'],
                createdAt: $row['created_at'],
                status: $row['status'],
            ),
            $results
        );
    }

    private function getCurrentUser(): string
    {
        return get_current_user() ?: 'unknown';
    }

    private function generateApprovalId(): string
    {
        return 'approval_' . bin2hex(random_bytes(8));
    }
}

final class ApprovalRequest
{
    public function __construct(
        public readonly string $id,
        public readonly array $migrations,
        public readonly string $requester,
        public readonly string $createdAt,
        public readonly string $status = 'pending',
        public readonly ?string $approver = null,
        public readonly ?string $approvedAt = null,
        public readonly ?string $notes = null,
    ) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function getMigrationCount(): int
    {
        return count($this->migrations);
    }
}

final class ApprovalException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
