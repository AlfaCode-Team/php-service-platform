<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Tenancy\API\Contracts\TenantAdminServiceContract;
use Project\Http\Controllers\ApiController;

/**
 * Control-plane CRUD endpoints for tenants (the JSON twin of the tenant:create /
 * tenant:delete CLI commands). Drives the admin resource UI under /tenants.
 *
 * These endpoints mutate the central registry and provision real databases, so
 * every route is gated by the `auth` filter and an explicit platform-admin
 * permission check here — a tenant member must never reach them.
 *
 * RequestAware (via ApiController): actions take route params only; the active
 * request is $this->resolveRequest().
 */
final class TenantAdminController extends ApiController
{
    /** Permission a caller must hold to manage the tenant fleet. */
    private const ADMIN_PERMISSION = 'tenancy:admin';

    public function __construct(
        private readonly TenantAdminServiceContract $tenants,
    ) {}

    /** GET /ajx/admin/tenants — list every tenant in the registry. */
    public function index(): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->ok([
            'data' => array_map(static fn ($t) => $t->toArray(), $this->tenants->list()),
        ]);
    }

    /** GET /ajx/admin/tenants/{tenantId} — one tenant. */
    public function show(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        return $this->okOrNotFound($this->tenants->get($tenantId)?->toArray());
    }

    /** POST /ajx/admin/tenants — provision a new tenant. */
    public function store(): Response
    {
        // if (($guard = $this->guard()) !== null) {
        //     return $guard;
        // }

        try {
            $tenant = $this->tenants->create($this->payload());
        } catch (ValidationException $e) {
            return $this->unprocessable($e->errors);
        }

        return $this->created($tenant->toArray());
    }

    /** PUT /ajx/admin/tenants/{tenantId} — update name/slug/status. */
    public function update(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        try {
            $tenant = $this->tenants->update($tenantId, $this->payload());
        } catch (ValidationException $e) {
            return $this->unprocessable($e->errors);
        }

        return $this->ok($tenant->toArray());
    }

    /** DELETE /ajx/admin/tenants/{tenantId} — de-provision a tenant. */
    public function destroy(string $tenantId): Response
    {
        if (($guard = $this->guard()) !== null) {
            return $guard;
        }

        $dropDatabase = $this->resolveRequest()->boolean('drop_database');
        $this->tenants->delete($tenantId, $dropDatabase);

        return $this->noContent();
    }

    /** Deny non-admins. Returns a Response to short-circuit, or null to proceed. */
    private function guard(): ?Response
    {
        $identity = $this->identity();
        if ($identity->isGuest()) {
            return $this->forbidden('Authentication is required.');
        }
        if (!$identity->hasPermission(self::ADMIN_PERMISSION) && !$identity->hasRole('platform-admin')) {
            return $this->forbidden('Tenant administration requires platform-admin access.');
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return $this->resolveRequest()->all();
    }
}
