<?php

declare(strict_types=1);

namespace Plugins\Invoice\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;

final class InvoiceRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity     $identity,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        try {
            return $this->db->query(
                'SELECT * FROM invoices WHERE tenant_id = :tenant',
                ['tenant' => $this->identity->tenantId]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list invoices', layer: 'repository.invoice', previous: $e);
        }
    }

    /** @return array<string,mixed> */
    public function find(string $id): array
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM invoices WHERE id = :id AND tenant_id = :tenant',
                ['id' => $id, 'tenant' => $this->identity->tenantId]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException("Failed to find invoice [$id]", layer: 'repository.invoice', previous: $e);
        }

        if ($row === null) {
            throw new RepositoryException("Invoice [$id] not found", layer: 'repository.invoice');
        }

        return $row;
    }
}
