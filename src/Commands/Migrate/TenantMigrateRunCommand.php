<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Support\JsonResultPresenter;

/**
 * tenant:migrate — run migrations for one tenant (--tenant=ID) or every
 * registered tenant (--all). Wraps TenantAwareRunner.
 */
final class TenantMigrateRunCommand extends TenantCommand
{
    protected function configure(): void
    {
        $this->name        = 'tenant:migrate';
        $this->description = 'Run migrations across one or all tenants';
        $this->registerTenantOptions();
    }

    protected function handle(): int
    {
        $runner = $this->tenantRunner();
        $tenant = $this->selectedTenant();

        if ($tenant !== null) {
            $this->info("Migrating tenant: {$tenant}");
            $result = $runner->runForTenant($tenant);

            if ($this->wantsJson()) {
                $this->emitJson([
                    'tenant' => $tenant,
                    'result' => (new JsonResultPresenter())->resultData($result),
                ]);
                return self::SUCCESS;
            }

            $this->alertSuccess('Tenant migrated', [
                "Tenant: {$tenant}",
                "Applied: {$result->appliedCount()}",
                "Batch:   {$result->batch}",
            ]);
            return self::SUCCESS;
        }

        // --all
        $this->info('Migrating ALL tenants…');
        $results = $runner->runForAllTenants();

        if ($this->wantsJson()) {
            $payload = [];
            foreach ($results as $id => $result) {
                $payload[$id] = (new JsonResultPresenter())->resultData($result);
            }
            $this->emitJson(['tenants' => $payload, 'count' => count($results)]);
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($results as $id => $result) {
            $rows[] = [$id, (string) $result->appliedCount(), (string) $result->batch];
        }
        $this->table()
            ->headers(['Tenant', 'Applied', 'Batch'])
            ->rows($rows)
            ->render();
        $this->alertSuccess('All tenants migrated', [
            'Tenants: ' . count($results),
        ]);
        return self::SUCCESS;
    }
}