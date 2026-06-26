<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\HostVerificationInstructions;
use Plugins\Tenancy\API\DTOs\HostVerificationResult;
use Plugins\Tenancy\Domain\Entities\TenantHost;

/**
 * TenantHostServiceContract — the management API for a tenant's custom domains.
 *
 * Drives the UI: list / add / remove hosts, fetch the DNS challenge, run the
 * ownership check (which SCANS the live DNS records for the verification token),
 * and choose the primary host. Every operation is scoped to ONE tenant id — a
 * tenant can only ever see or mutate its own hosts.
 */
interface TenantHostServiceContract
{
    /**
     * All hosts registered for a tenant (any status).
     *
     * @return list<TenantHost>
     */
    public function list(string $tenantId): array;

    /**
     * Register a new host for the tenant in Pending state and return the DNS
     * proof-of-ownership instructions. Optionally pin an expected A target.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\InvalidHostnameException
     * @throws \Plugins\Tenancy\Domain\Exceptions\HostConflictException
     */
    public function add(string $tenantId, string $hostname, ?string $expectedIp = null): HostVerificationInstructions;

    /**
     * The DNS challenge for an existing Pending host (re-show in the UI).
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\HostNotFoundException
     */
    public function instructions(string $tenantId, int $hostId): HostVerificationInstructions;

    /**
     * Run the ownership check: scan the host's live DNS for the verification
     * token (TXT) and, when configured, the expected A record. On success the
     * host is promoted to Verified and becomes routable; on failure it is marked
     * Failed with the observed records returned for diagnostics.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\HostNotFoundException
     */
    public function verify(string $tenantId, int $hostId): HostVerificationResult;

    /**
     * Promote a VERIFIED host to the tenant's primary (canonical) host; demotes
     * any previous primary. The redirect target the app should canonicalise to.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\HostNotFoundException
     */
    public function makePrimary(string $tenantId, int $hostId): TenantHost;

    /**
     * Remove (soft-delete) a host so it stops routing immediately.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\HostNotFoundException
     */
    public function remove(string $tenantId, int $hostId): void;
}
