<?php

declare(strict_types=1);

namespace Plugins\Settings\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Settings\Domain\Entities\TenantSettings;
use Plugins\Settings\Domain\ValueObjects\SettingsSection;

/**
 * Persists the central `tenant_settings_*` tables. Each table is a singleton row
 * keyed by `tenant_id`, so reads return one row (or null) and writes are portable
 * upserts on the `tenant_id` conflict key.
 *
 * The target table is never a free-form string: it comes from {@see SettingsSection},
 * so no caller input is interpolated into SQL.
 */
final class SettingsRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    /** Hydrate the tenant's row for a section as a {@see TenantSettings} entity. */
    public function fetch(SettingsSection $section, string $tenantId): ?TenantSettings
    {
        $table = $section->table();

        try {
            $row = $this->db->queryOne(
                "SELECT * FROM {$table} WHERE tenant_id = :tid",
                ['tid' => $tenantId],
            );

            return $row === null ? null : TenantSettings::fromRow($section, $row);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to load settings [{$table}] for tenant [{$tenantId}].",
                layer: 'repository.settings',
                context: ['table' => $table, 'tenant_id' => $tenantId],
                previous: $e,
            );
        }
    }

    /**
     * Idempotent upsert keyed on `tenant_id`. The entity carries its target
     * section and a tenant-scoped row (JSON columns already encoded).
     */
    public function upsert(TenantSettings $settings): void
    {
        $table = $settings->table();

        try {
            $this->db->upsert($table, $settings->toRawArray(), ['tenant_id']);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save settings [{$table}] for tenant [{$settings->tenantId()}].",
                layer: 'repository.settings',
                context: ['table' => $table],
                previous: $e,
            );
        }
    }
}
