<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Project\Support\Entity\Entity;

/**
 * Tenant — a view of one row of the central `tenants` registry.
 *
 * Carries the connection coordinates needed to reach the tenant's isolated
 * database. The password is stored ENCRYPTED here (dbPasswordEnc); decryption
 * happens only at the moment a connection is built, inside the resolver, never
 * in this entity and never in a log — so it is $hidden from serialization/dumps.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 *
 * Domain layer: zero external imports beyond Domain/ and the Project Entity base.
 */
final class Tenant extends Entity
{
    protected string $primaryKey = 'tenantId';

    /** The encrypted DB password never appears in dumps/serialization. */
    protected array $hidden = ['dbPasswordEnc'];

    /**
     * Build a brand-new provisioning tenant. $dbPasswordEnc must ALREADY be the
     * ciphertext — this entity never sees or stores the plaintext credential.
     */
    public static function create(
        string $tenantId,
        string $name,
        string $slug,
        string $dbDriver,
        string $dbHost,
        int $dbPort,
        string $dbName,
        string $dbUsername,
        string $dbPasswordEnc,
        TenantStatus $status,
        int $schemaVersion = 0,
        ?string $dbShard = null,
    ): self {
        $t = (new self())->forceFill([
            'tenantId'      => $tenantId,
            'name'          => $name,
            'slug'          => $slug,
            'dbDriver'      => $dbDriver,
            'dbHost'        => $dbHost,
            'dbPort'        => $dbPort,
            'dbName'        => $dbName,
            'dbUsername'    => $dbUsername,
            'dbPasswordEnc' => $dbPasswordEnc,
            'status'        => $status,
            'schemaVersion' => $schemaVersion,
            'dbShard'       => $dbShard,
        ]);
        $t->syncOriginal();

        return $t;
    }

    /**
     * Hydrate from a central-DB row. Reconstitution only — records no events.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $t = (new self())->forceFill([
            'tenantId'      => (string) $row['tenant_id'],
            'name'          => (string) $row['name'],
            'slug'          => (string) $row['slug'],
            'dbDriver'      => (string) $row['db_driver'],
            'dbHost'        => (string) $row['db_host'],
            'dbPort'        => (int) $row['db_port'],
            'dbName'        => (string) $row['db_name'],
            'dbUsername'    => (string) $row['db_username'],
            'dbPasswordEnc' => (string) $row['db_password_enc'],
            'status'        => TenantStatus::from((int) $row['status']),
            'schemaVersion' => (int) ($row['schema_version'] ?? 0),
            'dbShard'       => isset($row['db_shard']) ? (string) $row['db_shard'] : null,
        ]);
        $t->syncOriginal();

        return $t;
    }

    /** Stable connection name in the ConnectionManager registry. */
    public function connectionName(): string
    {
        return 'tenant:' . $this->tenantId;
    }
}
