<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\DTOs;

use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * Admin-facing view of one central `tenants` registry row.
 *
 * Carries everything the control-plane UI needs to list/edit a tenant EXCEPT
 * the encrypted database password — that secret never crosses this boundary.
 */
final readonly class TenantDetail
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
        public string $status,
        public int $schemaVersion,
    ) {}

    public static function fromEntity(Tenant $t): self
    {
        return new self(
            tenantId:      $t->tenantId,
            name:          $t->name,
            slug:          $t->slug,
            dbDriver:      $t->dbDriver,
            dbHost:        $t->dbHost,
            dbPort:        $t->dbPort,
            dbName:        $t->dbName,
            dbUsername:    $t->dbUsername,
            status:        strtolower($t->status->name),
            schemaVersion: $t->schemaVersion,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenantId'      => $this->tenantId,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'dbDriver'      => $this->dbDriver,
            'dbHost'        => $this->dbHost,
            'dbPort'        => $this->dbPort,
            'dbName'        => $this->dbName,
            'dbUsername'    => $this->dbUsername,
            'status'        => $this->status,
            'schemaVersion' => $this->schemaVersion,
        ];
    }
}
