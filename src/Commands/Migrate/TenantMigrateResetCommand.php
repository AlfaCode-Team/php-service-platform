<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\JsonResultPresenter;

/**
 * tenant:reset — drop all migrations per tenant (destructive).
 */
final class TenantMigrateResetCommand extends TenantCommand
{
    protected function configure(): void
    {
        $this->name        = 'tenant:reset';
        $this->description = 'Reset (rollback all) migrations across one or all tenants — DESTRUCTIVE';
        $this->registerTenantOptions();
    }

    protected function handle(): int
    {
        $runner = $this->tenantRunner();
        $tenant = $this->selectedTenant();

        if ($tenant !== null) {
            $this->info("Resetting tenant: {$tenant}");
            $result = $runner->resetForTenant($tenant);

            if ($this->wantsJson()) {
                $this->emitJson([
                    'tenant' => $tenant,
                    'result' => (new JsonResultPresenter())->resultData($result),
                ]);
                return self::SUCCESS;
            }

            $this->alertSuccess('Tenant reset', [
                "Tenant: {$tenant}",
                "Rolled back: " . count((array) $result->rolledBack),
            ]);
            return self::SUCCESS;
        }

        $this->info('Resetting ALL tenants…');
        $results = [];
        foreach (array_keys($runner->statusForAllTenants()) as $id) {
            $results[$id] = $runner->resetForTenant($id);
        }

        if ($this->wantsJson()) {
            $payload = [];
            foreach ($results as $id => $result) {
                $payload[$id] = (new JsonResultPresenter())->resultData($result);
            }
            $this->emitJson(['tenants' => $payload]);
            return self::SUCCESS;
        }

        $this->alertSuccess('All tenants reset', ['Tenants: ' . count($results)]);
        return self::SUCCESS;
    }
}