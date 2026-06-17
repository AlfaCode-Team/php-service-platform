<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

/**
 * tenant:status — show migration status per tenant.
 */
final class TenantMigrateStatusCommand extends TenantCommand
{
    protected function configure(): void
    {
        $this->name        = 'tenant:status';
        $this->description = 'Show migration status across one or all tenants';
        $this->registerTenantOptions();
    }

    protected function handle(): int
    {
        $runner = $this->tenantRunner();
        $tenant = $this->selectedTenant();

        if ($tenant !== null) {
            $status = $runner->statusForTenant($tenant);

            if ($this->wantsJson()) {
                $this->emitJson(['tenant' => $tenant, 'status' => $status]);
                return self::SUCCESS;
            }

            $this->section("Status for tenant: {$tenant}");
            $rows = [];
            foreach ($status as $name => $row) {
                $rows[] = [
                    $name,
                    (string) ($row['status'] ?? '?'),
                    $row['batch'] !== null ? (string) $row['batch'] : '—',
                ];
            }
            $this->table()
                ->headers(['Migration', 'Status', 'Batch'])
                ->rows($rows)
                ->render();
            return self::SUCCESS;
        }

        $all = $runner->statusForAllTenants();

        if ($this->wantsJson()) {
            $this->emitJson(['tenants' => $all]);
            return self::SUCCESS;
        }

        // Render a flat aggregate: tenant + migration name + status + batch
        foreach ($all as $id => $status) {
            $this->section("Tenant: {$id}");
            $rows = [];
            foreach ($status as $name => $row) {
                $rows[] = [
                    $name,
                    (string) ($row['status'] ?? '?'),
                    $row['batch'] !== null ? (string) $row['batch'] : '—',
                ];
            }
            $this->table()
                ->headers(['Migration', 'Status', 'Batch'])
                ->rows($rows)
                ->render();
        }
        return self::SUCCESS;
    }
}