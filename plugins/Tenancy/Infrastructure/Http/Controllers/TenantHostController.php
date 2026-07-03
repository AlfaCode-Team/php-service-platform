<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\TenantHostServiceContract;
use Plugins\Tenancy\Domain\Exceptions\HostConflictException;
use Plugins\Tenancy\Domain\Exceptions\HostNotFoundException;
use Plugins\Tenancy\Domain\Exceptions\HostQuotaExceededException;
use Plugins\Tenancy\Domain\Exceptions\InvalidHostnameException;
use Project\Http\Controllers\ApiController;

/**
 * TenantHostController — the management UI boundary for a tenant's custom domains.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is $this->request. The tenant id ALWAYS comes from the verified,
 * tenant-scoped Identity (`tenantId` claim) — never the request body — so a
 * caller can only ever manage hosts for the tenant they are currently scoped to.
 * Every route carries the `auth` filter; a host-admin permission can be layered
 * on at the route too.
 */
final class TenantHostController extends ApiController
{
    public function __construct(
        private readonly TenantHostServiceContract $hosts,
    ) {}

    /** GET /ajx/tenant/hosts — list every host for the current tenant. */
    public function index(): Response
    {
        $tenantId = $this->requireTenant();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $hosts = $this->hosts->list($tenantId);

        return $this->ok(['data' => array_map(static fn ($h) => $h->toArray(), $hosts)]);
    }

    /** POST /ajx/tenant/hosts — register a host; returns DNS challenge to publish. */
    public function store(): Response
    {
        $tenantId = $this->requireHostManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $hostname = trim((string) $this->request?->input('hostname', ''));
        if ($hostname === '') {
            return $this->unprocessable(['hostname' => 'A hostname is required.']);
        }

        $expectedIp = $this->request?->input('ip_address');
        $expectedIp = is_string($expectedIp) && trim($expectedIp) !== '' ? trim($expectedIp) : null;

        try {
            $instructions = $this->hosts->add($tenantId, $hostname, $expectedIp);
        } catch (InvalidHostnameException $e) {
            return $this->unprocessable(['hostname' => $e->getMessage()]);
        } catch (HostQuotaExceededException $e) {
            return $this->unprocessable(['hostname' => $e->getMessage()]);
        } catch (HostConflictException $e) {
            return $this->conflict($e->getMessage());
        }

        return $this->created($instructions->toArray());
    }

    /** GET /ajx/tenant/hosts/{hostId}/instructions — re-show the DNS challenge. */
    public function instructions(string $hostId): Response
    {
        $tenantId = $this->requireTenant();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $instructions = $this->hosts->instructions($tenantId, (int) $hostId);
        } catch (HostNotFoundException) {
            return $this->notFound('Host not found.');
        }

        return $this->ok($instructions->toArray());
    }

    /** POST /ajx/tenant/hosts/{hostId}/verify — scan DNS and (de)verify the host. */
    public function verify(string $hostId): Response
    {
        $tenantId = $this->requireHostManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $result = $this->hosts->verify($tenantId, (int) $hostId);
        } catch (HostNotFoundException) {
            return $this->notFound('Host not found.');
        }

        // 200 either way — the body's `verified` flag tells the UI the outcome;
        // a failed DNS check is not an HTTP error.
        return $this->ok($result->toArray());
    }

    /** POST /ajx/tenant/hosts/{hostId}/primary — make a verified host canonical. */
    public function makePrimary(string $hostId): Response
    {
        $tenantId = $this->requireHostManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $host = $this->hosts->makePrimary($tenantId, (int) $hostId);
        } catch (HostNotFoundException) {
            return $this->notFound('Host not found, or not yet verified.');
        }

        return $this->ok($host->toArray());
    }

    /** DELETE /ajx/tenant/hosts/{hostId} — stop routing a host. */
    public function destroy(string $hostId): Response
    {
        $tenantId = $this->requireHostManager();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        try {
            $this->hosts->remove($tenantId, (int) $hostId);
        } catch (HostNotFoundException) {
            return $this->notFound('Host not found.');
        }

        return $this->noContent();
    }

    /**
     * The tenant the caller is currently scoped to, or a 403 Response when the
     * request is unauthenticated / not yet tenant-scoped (pick a tenant first).
     */
    private function requireTenant(): string|Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }
        if ($identity->tenantId === '') {
            return $this->forbidden('Select a tenant before managing its hosts.');
        }

        return $identity->tenantId;
    }

    /**
     * Like {@see requireTenant()} but also requires the caller be allowed to
     * MANAGE hosts — a privileged action (it changes which domains route to the
     * tenant). Gate: the `tenant.hosts.manage` permission OR an owner/admin role
     * on the active tenant-scoped Identity. Reading the list stays open to any
     * member.
     */
    private function requireHostManager(): string|Response
    {
        $tenantId = $this->requireTenant();
        if ($tenantId instanceof Response) {
            return $tenantId;
        }

        $identity = $this->identity();
        $allowed = $identity->hasPermission('tenant.hosts.manage')
            || $identity->hasRole('owner')
            || $identity->hasRole('admin');

        if (!$allowed) {
            return $this->forbidden('You are not allowed to manage this tenant\'s hosts.');
        }

        return $tenantId;
    }

    /** 409 Conflict in the standard error envelope. */
    private function conflict(string $message): Response
    {
        return Response::json(
            ['error' => ['code' => 'host.conflict', 'message' => $message]],
            409,
        );
    }
}
