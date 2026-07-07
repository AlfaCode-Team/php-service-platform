<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\TenantHostStore;
use Plugins\Tenancy\Domain\Entities\TenantHost;

/**
 * TenantHostRepository — reads/writes the central `tenant_hosts` table.
 *
 * Access rule: DatabasePort ONLY. The injected port is the CENTRAL connection
 * (the ConnectionManager default) — host management is a control-plane concern
 * and must never run against a tenant database. All mutating queries carry the
 * tenant_id in the WHERE clause so a host can only be touched by its owner.
 */
final class TenantHostRepository implements TenantHostStore
{
    private const COLUMNS =
        'host_id, tenant_id, hostname, ip_address, status,
         verification_token, is_primary, verified_at, created_at, updated_at';

    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function allForTenant(string $tenantId): array
    {
        try {
            $rows = $this->central->query(
                'SELECT ' . self::COLUMNS . '
                   FROM tenant_hosts
                  WHERE tenant_id = :tid AND deleted_at IS NULL
                  ORDER BY is_primary DESC, hostname ASC',
                ['tid' => $tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list tenant hosts.', layer: 'repository.tenancy', previous: $e);
        }

        return array_map(static fn (array $r): TenantHost => TenantHost::fromRow($r), $rows);
    }

    public function find(string $tenantId, int $hostId): ?TenantHost
    {
        try {
            $row = $this->central->queryOne(
                'SELECT ' . self::COLUMNS . '
                   FROM tenant_hosts
                  WHERE tenant_id = :tid AND host_id = :hid AND deleted_at IS NULL
                  LIMIT 1',
                ['tid' => $tenantId, 'hid' => $hostId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load tenant host.', layer: 'repository.tenancy', previous: $e);
        }

        return $row === null ? null : TenantHost::fromRow($row);
    }

    public function hostnameTaken(string $hostname): bool
    {
        try {
            $row = $this->central->queryOne(
                'SELECT host_id FROM tenant_hosts
                  WHERE hostname = :host AND deleted_at IS NULL
                  LIMIT 1',
                ['host' => $hostname],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to check hostname.', layer: 'repository.tenancy', previous: $e);
        }

        return $row !== null;
    }

    public function insert(string $tenantId, string $hostname, ?string $ipAddress, string $verificationToken): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $this->central->execute(
                'INSERT INTO tenant_hosts
                    (tenant_id, hostname, ip_address, status, verification_token, is_primary, created_at, updated_at)
                 VALUES (:tid, :host, :ip, 0, :token, 0, :created, :updated)',
                [
                    'tid'     => $tenantId,
                    'host'    => $hostname,
                    'ip'      => $ipAddress,
                    'token'   => $verificationToken,
                    'created' => $now,
                    'updated' => $now,
                ],
            );

            return (int) $this->central->lastInsertId();
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to register tenant host.', layer: 'repository.tenancy', previous: $e);
        }
    }

    public function markStatus(string $tenantId, int $hostId, int $status, ?string $verifiedAt): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $this->central->execute(
                'UPDATE tenant_hosts
                    SET status = :status, verified_at = :verified, updated_at = :now
                  WHERE tenant_id = :tid AND host_id = :hid AND deleted_at IS NULL',
                ['status' => $status, 'verified' => $verifiedAt, 'now' => $now, 'tid' => $tenantId, 'hid' => $hostId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to update host status.', layer: 'repository.tenancy', previous: $e);
        }
    }

    public function setPrimary(string $tenantId, int $hostId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $this->central->beginTransaction();

            // Demote every host of the tenant, then promote the chosen one — so a
            // tenant always has at most one primary.
            $this->central->execute(
                'UPDATE tenant_hosts SET is_primary = 0, updated_at = :now
                  WHERE tenant_id = :tid AND deleted_at IS NULL',
                ['now' => $now, 'tid' => $tenantId],
            );
            $this->central->execute(
                'UPDATE tenant_hosts SET is_primary = 1, updated_at = :now
                  WHERE tenant_id = :tid AND host_id = :hid AND deleted_at IS NULL',
                ['now' => $now, 'tid' => $tenantId, 'hid' => $hostId],
            );

            $this->central->commit();
        } catch (\Throwable $e) {
            if ($this->central->inTransaction()) {
                $this->central->rollback();
            }
            throw new RepositoryException('Failed to set primary host.', layer: 'repository.tenancy', previous: $e);
        }
    }

    public function softDelete(string $tenantId, int $hostId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            // Mangle the unique hostname on delete so the same hostname can be
            // re-registered later without colliding with this soft-deleted row.
            // (verification_token is already random-unique — no need to touch it.)
            // The mangling is done in PHP rather than via CONCAT()/SUBSTRING() so
            // the statement is driver-portable (MySQL CONCAT vs PostgreSQL/SQLite
            // `||`, SUBSTRING vs substr); only a bound value crosses the wire.
            $row = $this->central->queryOne(
                'SELECT hostname FROM tenant_hosts
                  WHERE tenant_id = :tid AND host_id = :hid AND deleted_at IS NULL',
                ['tid' => $tenantId, 'hid' => $hostId],
            );
            if ($row === null) {
                return; // already gone / not this tenant's — nothing to delete
            }

            $mangled = mb_substr((string) $row['hostname'], 0, 160) . '#del:' . $hostId . ':' . $now;

            $this->central->execute(
                'UPDATE tenant_hosts
                    SET deleted_at = :now, is_primary = 0, hostname = :hostname
                  WHERE tenant_id = :tid AND host_id = :hid AND deleted_at IS NULL',
                [
                    'now'      => $now,
                    'hostname' => $mangled,
                    'tid'      => $tenantId,
                    'hid'      => $hostId,
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to remove tenant host.', layer: 'repository.tenancy', previous: $e);
        }
    }
}
