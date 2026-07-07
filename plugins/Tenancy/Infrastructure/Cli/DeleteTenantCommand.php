<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Infrastructure\Cli\Concerns\ManagesTenantDatabase;

/**
 * tenant:delete — de-provision a tenant (the inverse of tenant:create).
 *
 * Looks the tenant up in the central registry (by --tenant id or --slug), then
 * drops its database user across every host, optionally drops the database, and
 * removes the registry row. DDL is not transactional on MySQL, so each teardown
 * step is isolated and the worst case is a clearly-reported partial cleanup.
 *
 *   hkm tenant:delete --slug=acme                 # drop user + registry row
 *   hkm tenant:delete --slug=acme --drop-database # also DROP DATABASE (destructive)
 *   hkm tenant:delete --tenant=<id> --yes         # no confirmation prompt
 */
final class DeleteTenantCommand extends AbstractCommand
{
    use ManagesTenantDatabase;

    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'tenant:delete';
        $this->description = 'De-provision a tenant: drop its DB user, optionally its database, and the registry row';

        $this->addOption('tenant', 't', 'Tenant id to delete', acceptsValue: true);
        $this->addOption('slug', '', 'Tenant slug to delete', acceptsValue: true);
        $this->addOption('drop-database', '', 'Also DROP the tenant database (DESTRUCTIVE — data is lost)');
        $this->addOption('yes', 'y', 'Skip the confirmation prompt');
    }

    protected function handle(): int
    {
        $id   = (string) $this->option('tenant');
        $slug = (string) $this->option('slug');

        if ($id === '' && $slug === '') {
            $this->error('Provide --tenant <id> or --slug <slug>.');
            return self::FAILURE;
        }

        $central = $this->connections->default();
        $tenant  = $this->resolve($central, $id, $slug);
        if ($tenant === null) {
            $this->error('Tenant not found in the registry.');
            return self::FAILURE;
        }

        $dropDatabase = $this->hasOption('drop-database');

        $this->alertInfo('Delete tenant', [
            "tenant   : {$tenant->slug} ({$tenant->tenantId})",
            "driver   : {$tenant->dbDriver}",
            "database : {$tenant->dbName} @ {$tenant->dbHost}",
            "username : {$tenant->dbUsername}",
            'database will be ' . ($dropDatabase ? 'DROPPED (data lost)' : 'KEPT'),
        ]);

        if (!$this->confirmDestruction()) {
            $this->warning('Aborted — nothing was deleted.');
            return self::SUCCESS;
        }

        $failed = 0;

        try {
            $this->dropDatabaseUser($central, $tenant->dbDriver, $tenant->dbUsername, $tenant->dbHost);
            $this->info("· dropped user [{$tenant->dbUsername}].");
        } catch (\Throwable $e) {
            $failed++;
            $this->error("· could not drop user [{$tenant->dbUsername}]: {$e->getMessage()}");
        }

        if ($dropDatabase) {
            try {
                $this->dropDatabase($central, $tenant->dbDriver, $tenant->dbName);
                $this->info("· dropped database [{$tenant->dbName}].");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("· could not drop database [{$tenant->dbName}]: {$e->getMessage()}");
            }
        }

        try {
            $central->execute('DELETE FROM tenants WHERE tenant_id = :id', ['id' => $tenant->tenantId]);
            $this->info('· removed registry row.');
        } catch (\Throwable $e) {
            $failed++;
            $this->error("· could not remove registry row: {$e->getMessage()}");
        }

        if ($failed > 0) {
            $this->warning("Tenant partially deleted — {$failed} step(s) failed (see above).");
            return self::FAILURE;
        }

        $this->success("Tenant [{$tenant->slug}] deleted.");

        return self::SUCCESS;
    }

    private function resolve(DatabasePort $central, string $id, string $slug): ?Tenant
    {
        $row = $id !== ''
            ? $central->queryOne('SELECT * FROM tenants WHERE tenant_id = :id', ['id' => $id])
            : $central->queryOne('SELECT * FROM tenants WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : Tenant::fromRow($row);
    }

    /**
     * Require an explicit yes: a prompt when interactive, or the --yes flag in a
     * non-interactive context (so this destructive command never runs blind).
     */
    private function confirmDestruction(): bool
    {
        if ($this->hasOption('yes')) {
            return true;
        }

        if (\function_exists('stream_isatty') && @stream_isatty(\STDIN)) {
            return $this->confirm('Delete this tenant now?', false);
        }

        $this->error('Refusing to delete non-interactively without --yes.');

        return false;
    }
}
