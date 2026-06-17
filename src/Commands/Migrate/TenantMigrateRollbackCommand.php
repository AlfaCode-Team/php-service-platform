<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\JsonResultPresenter;

/**
 * tenant:rollback — roll back last N batches per tenant.
 */
final class TenantMigrateRollbackCommand extends TenantCommand
{
    protected function configure(): void
    {
        $this->name        = 'tenant:rollback';
        $this->description = 'Roll back the last N migration batches across one or all tenants';
        $this->registerTenantOptions();
        $this->addOption('steps', 's',
            'Number of batches to roll back',
            acceptsValue: true, default: '1');
    }

    protected function handle(): int
    {
        $runner = $this->tenantRunner();
        $tenant = $this->selectedTenant();
        $steps  = max(1, (int) $this->option('steps', '1'));

        if ($tenant !== null) {
            $this->info("Rolling back tenant: {$tenant} (steps: {$steps})");
            $result = $runner->rollbackForTenant($tenant, $steps);

            if ($this->wantsJson()) {
                $this->emitJson([
                    'tenant' => $tenant,
                    'steps'  => $steps,
                    'result' => (new JsonResultPresenter())->resultData($result),
                ]);
                return self::SUCCESS;
            }

            $this->alertSuccess('Rollback complete', [
                "Tenant:      {$tenant}",
                "Rolled back: " . count((array) $result->rolledBack),
            ]);
            return self::SUCCESS;
        }

        // --all
        $this->info("Rolling back ALL tenants (steps: {$steps})…");
        $results = [];
        foreach (array_keys($this->tenantRunner()->statusForAllTenants()) as $id) {
            $results[$id] = $runner->rollbackForTenant($id, $steps);
        }

        if ($this->wantsJson()) {
            $payload = [];
            foreach ($results as $id => $result) {
                $payload[$id] = (new JsonResultPresenter())->resultData($result);
            }
            $this->emitJson(['tenants' => $payload, 'steps' => $steps]);
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $id => $result) {
            $rows[] = [$id, (string) count((array) $result->rolledBack)];
        }
        $this->table()
            ->headers(['Tenant', 'Rolled back'])
            ->rows($rows)
            ->render();
        $this->alertSuccess('All tenants rolled back', [
            'Tenants: ' . count($results),
            'Steps:   ' . $steps,
        ]);
        return self::SUCCESS;
    }
}