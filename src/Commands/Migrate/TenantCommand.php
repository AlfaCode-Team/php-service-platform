<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Migrate;

use AlfaCode\LetMigrate\Contract\TenantResolverInterface;
use AlfaCode\LetMigrate\Tenant\TenantAwareRunner;
use Psr\Log\NullLogger;

/**
 * Base class for tenant-aware CLI commands.
 *
 * Operates on top of LetMigrateCommand to inherit config loading + the
 * universal --config/--connection/--json options, and adds:
 *
 *   --tenant=ID    Operate on a single tenant
 *   --all          Operate on every tenant the resolver returns
 *
 * Exactly one of --tenant or --all is required.
 *
 * Tenant resolution
 * ─────────────────
 *
 * The config file must declare a tenant resolver under the 'tenants' key.
 * Two shapes are accepted:
 *
 *   // Shape A — instance directly:
 *   return [
 *       'paths'   => [__DIR__ . '/migrations'],
 *       'tenants' => [
 *           'resolver' => new MyTenantResolver($pdo),
 *       ],
 *   ];
 *
 *   // Shape B — class name (default-constructible):
 *   return [
 *       'paths'   => [__DIR__ . '/migrations'],
 *       'tenants' => [
 *           'resolver_class' => MyTenantResolver::class,
 *       ],
 *   ];
 *
 * Subclasses implement runForTenants(TenantAwareRunner $runner): int — they
 * receive a wired runner and decide which method to call.
 */
abstract class TenantCommand extends LetMigrateCommand
{
    private ?TenantAwareRunner $cachedTenantRunner = null;

    /**
     * Subclasses MUST register their own name/description and call this from
     * configure() to pick up the standard tenant options.
     */
    protected function registerTenantOptions(): void
    {
        $this->registerCommonOptions();
        $this->addOption('tenant', 't',
            'Tenant ID to operate on',
            acceptsValue: true);
        $this->addOption('all', '',
            'Operate on every registered tenant in sequence');
    }

    /**
     * Build (and cache) the TenantAwareRunner from config.
     *
     * Validates that exactly one of --tenant / --all was supplied. Throws a
     * clear runtime error if the config has no 'tenants' section or if the
     * resolver can't be constructed.
     */
    protected function tenantRunner(): TenantAwareRunner
    {
        if ($this->cachedTenantRunner !== null) {
            return $this->cachedTenantRunner;
        }

        $config  = $this->loadConfig();
        $tenants = $config['tenants'] ?? null;
        if (!is_array($tenants)) {
            $this->error(
                'No tenant configuration. Add a "tenants" key to your config '
                . 'with either "resolver" (instance) or "resolver_class" (FQCN).',
            );
            throw new \RuntimeException('Missing tenants config.');
        }

        $resolver = $this->buildResolver($tenants);

        // Strip the 'tenants' key from base config so it doesn't leak into
        // per-tenant DriverRegistry::fromConfig() calls.
        $baseConfig = array_diff_key($config, ['tenants' => 1]);

        return $this->cachedTenantRunner = new TenantAwareRunner(
            resolver:   $resolver,
            baseConfig: $baseConfig,
            logger:     new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $tenants
     */
    private function buildResolver(array $tenants): TenantResolverInterface
    {
        // Shape A — instance.
        if (isset($tenants['resolver'])) {
            $r = $tenants['resolver'];
            if ($r instanceof TenantResolverInterface) {
                return $r;
            }
            // Closure → call it.
            if (is_callable($r)) {
                $resolved = $r();
                if ($resolved instanceof TenantResolverInterface) {
                    return $resolved;
                }
            }
            throw new \RuntimeException(
                'tenants.resolver did not produce a TenantResolverInterface.',
            );
        }

        // Shape B — class name.
        if (isset($tenants['resolver_class'])) {
            $class = (string) $tenants['resolver_class'];
            if (!class_exists($class)) {
                throw new \RuntimeException(
                    "tenants.resolver_class '{$class}' does not exist.",
                );
            }
            $instance = new $class();
            if (!$instance instanceof TenantResolverInterface) {
                throw new \RuntimeException(
                    "tenants.resolver_class '{$class}' must implement "
                    . 'AlfaCode\\LetMigrate\\Contract\\TenantResolverInterface.',
                );
            }
            return $instance;
        }

        throw new \RuntimeException(
            'tenants.resolver or tenants.resolver_class is required.',
        );
    }

    /**
     * Convenience: validate that exactly one of --tenant or --all was given,
     * and return the chosen tenant ID (or null when --all).
     */
    protected function selectedTenant(): ?string
    {
        $tenant = $this->option('tenant');
        $all    = $this->hasOption('all');

        if ($tenant === null && !$all) {
            $this->error('Specify exactly one of --tenant=ID or --all.');
            throw new \RuntimeException('Tenant target missing.');
        }
        if ($tenant !== null && $all) {
            $this->error('Cannot combine --tenant=ID with --all — pick one.');
            throw new \RuntimeException('Conflicting tenant flags.');
        }

        return $tenant !== null ? (string) $tenant : null;
    }
}