<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\JsonResultPresenter;

/**
 * tenant:refresh — reset then re-run all migrations per tenant.
 */
final class TenantMigrateRefreshCommand extends TenantCommand
{
    protected function configure(): void
    {
        $this->name        = 'tenant:refresh';
        $this->description = 'Reset and re-run all migrations across one or all tenants';
        $this->registerTenantOptions();
    }

    protected function handle(): int
    {
        $runner = $this->tenantRunner();
        $tenant = $this->selectedTenant();

        if ($tenant !== null) {
            $this->info("Refreshing tenant: {$tenant}");
            $result = $runner->refreshForTenant($tenant);

            if ($this->wantsJson()) {
                $this->emitJson([
                    'tenant' => $tenant,
                    'result' => (new JsonResultPresenter())->resultData($result),
                ]);
                return self::SUCCESS;
            }

            $this->alertSuccess('Tenant refreshed', [
                "Tenant:  {$tenant}",
                "Applied: {$result->appliedCount()}",
            ]);
            return self::SUCCESS;
        }

        $this->info('Refreshing ALL tenants…');
        $results = [];
        foreach (array_keys($runner->statusForAllTenants()) as $id) {
            $results[$id] = $runner->refreshForTenant($id);
        }

        if ($this->wantsJson()) {
            $payload = [];
            foreach ($results as $id => $result) {
                $payload[$id] = (new JsonResultPresenter())->resultData($result);
            }
            $this->emitJson(['tenants' => $payload]);
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $id => $result) {
            $rows[] = [$id, (string) $result->appliedCount()];
        }
        $this->table()
            ->headers(['Tenant', 'Applied'])
            ->rows($rows)
            ->render();
        $this->alertSuccess('All tenants refreshed', ['Tenants: ' . count($results)]);
        return self::SUCCESS;
    }
}