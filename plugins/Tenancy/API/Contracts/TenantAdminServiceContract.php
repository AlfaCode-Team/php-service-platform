<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\TenantDetail;

/**
 * TenantAdminServiceContract — control-plane CRUD over the central `tenants`
 * registry, the HTTP-facing twin of the tenant:create / tenant:delete CLI
 * commands.
 *
 * create() provisions the full stack (registry row + isolated database + user +
 * template migrations) with compensating teardown on failure, exactly like the
 * CLI. update() only mutates safe metadata (name / slug / status) — it never
 * rewrites a live tenant's connection coordinates. delete() de-provisions.
 */
interface TenantAdminServiceContract
{
    /**
     * Every tenant in the registry, newest-meaningful order.
     *
     * @return list<TenantDetail>
     */
    public function list(): array;

    /** One tenant by id, or null when it does not exist. */
    public function get(string $tenantId): ?TenantDetail;

    /**
     * Provision a brand new tenant.
     *
     * @param array{name:string,slug:string,driver:string,db_host:string,db_port:int,db_name:string,db_user:string,db_password:string} $input
     */
    public function create(array $input): TenantDetail;

    /**
     * Update safe metadata only (name, slug, status). Unknown/absent keys are
     * left untouched.
     *
     * @param array{name?:string,slug?:string,status?:string} $input
     */
    public function update(string $tenantId, array $input): TenantDetail;

    /** De-provision a tenant: drop its DB user, optionally its database, and the row. */
    public function delete(string $tenantId, bool $dropDatabase = false): void;
}
