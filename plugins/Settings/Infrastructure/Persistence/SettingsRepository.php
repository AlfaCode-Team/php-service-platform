<?php

declare(strict_types=1);

namespace Plugins\Settings\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
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

    /**
     * @return array<string, mixed>|null
     */
    public function fetch(SettingsSection $section, string $tenantId): ?array
    {
        $table = $section->table();

        try {
            return $this->db->queryOne(
                "SELECT * FROM {$table} WHERE tenant_id = :tid",
                ['tid' => $tenantId],
            );
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
     * Idempotent upsert keyed on `tenant_id`. `$row` MUST contain `tenant_id`
     * and use raw column names (JSON columns already encoded).
     *
     * @param array<string, mixed> $row
     */
    public function upsert(SettingsSection $section, array $row): void
    {
        $table = $section->table();

        try {
            $this->db->upsert($table, $row, ['tenant_id']);
        } catch (\Throwable $e) {
            throw new RepositoryException(
                "Failed to save settings [{$table}] for tenant [" . ($row['tenant_id'] ?? '?') . '].',
                layer: 'repository.settings',
                context: ['table' => $table],
                previous: $e,
            );
        }
    }
}
