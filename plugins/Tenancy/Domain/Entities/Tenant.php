<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

/**
 * Tenant — an immutable view of one row of the central `tenants` registry.
 *
 * Carries the connection coordinates needed to reach the tenant's isolated
 * database. The password is stored ENCRYPTED here (db_password_enc); decryption
 * happens only at the moment a connection is built, inside the resolver, never
 * in this value object and never in a log.
 *
 * Domain layer: zero external imports beyond Domain/.
 */
final readonly class Tenant
{
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $slug,
        public string $dbDriver,
        public string $dbHost,
        public int $dbPort,
        public string $dbName,
        public string $dbUsername,
        public string $dbPasswordEnc,
        public TenantStatus $status,
        public int $schemaVersion,
        public ?string $dbShard = null,
    ) {}

    /**
     * Hydrate from a central-DB row. Reconstitution only — records no events.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            tenantId:      (string) $row['tenant_id'],
            name:          (string) $row['name'],
            slug:          (string) $row['slug'],
            dbDriver:      (string) $row['db_driver'],
            dbHost:        (string) $row['db_host'],
            dbPort:        (int) $row['db_port'],
            dbName:        (string) $row['db_name'],
            dbUsername:    (string) $row['db_username'],
            dbPasswordEnc: (string) $row['db_password_enc'],
            status:        TenantStatus::from((int) $row['status']),
            schemaVersion: (int) ($row['schema_version'] ?? 0),
            dbShard:       isset($row['db_shard']) ? (string) $row['db_shard'] : null,
        );
    }

    /** Stable connection name in the ConnectionManager registry. */
    public function connectionName(): string
    {
        return 'tenant:' . $this->tenantId;
    }
}
