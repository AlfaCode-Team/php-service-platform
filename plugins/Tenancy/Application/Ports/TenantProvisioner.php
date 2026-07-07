<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\Tenant;

/**
 * TenantProvisioner — the data-plane boundary for tenant infrastructure.
 *
 * Encapsulates the non-transactional DDL (CREATE/DROP DATABASE, CREATE/DROP
 * USER, GRANT) and the template-migration run. Implemented by an Infrastructure
 * adapter; the Application service orchestrates it through THIS interface so the
 * migration engine and driver-specific SQL never leak into the service layer.
 */
interface TenantProvisioner
{
    /** Does the tenant's physical database already exist? */
    public function databaseExists(Tenant $tenant): bool;

    /**
     * Provision the isolated database + user and run the template migrations.
     * Throws on any failure; the caller compensates with {@see teardown()}.
     *
     * @param string $plainPassword the un-encrypted tenant DB password
     * @param bool   $databaseAlreadyExists skip CREATE DATABASE when true
     */
    public function provision(Tenant $tenant, string $plainPassword, bool $databaseAlreadyExists): void;

    /**
     * Best-effort teardown of the tenant's infrastructure (idempotent). Drops
     * the DB user, and the database too when $dropDatabase is true.
     *
     * @return int number of steps that failed (0 = clean)
     */
    public function teardown(Tenant $tenant, bool $dropDatabase): int;
}
