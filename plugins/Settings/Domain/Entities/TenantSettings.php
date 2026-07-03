<?php

declare(strict_types=1);

namespace Plugins\Settings\Domain\Entities;

use Plugins\Settings\Domain\ValueObjects\SettingsSection;
use Project\Support\Entity\Entity;

/**
 * TenantSettings — one tenant's row of a single settings section
 * (`tenant_settings_company`, `_contact`, `_email`, `_email_providers`,
 * `_system`). Each section table is a singleton row keyed by `tenant_id`.
 *
 * Built on the shared {@see Entity} attribute-bag base so a section row can be
 * hydrated, normalised and persisted generically while the section-specific
 * shape + request validation stay in the API DTOs at the edge. The owning
 * {@see SettingsSection} rides alongside the bag (it is the persistence target,
 * not a column) so the repository never interpolates a caller string into SQL.
 */
final class TenantSettings extends Entity
{
    protected string $primaryKey = 'tenant_id';

    private SettingsSection $section;

    /**
     * Hydrate an existing section row. Records no events.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(SettingsSection $section, array $row): self
    {
        $e = new self($row);
        $e->section = $section;

        return $e;
    }

    /**
     * Build a section row staged for persistence. The row MUST carry a non-empty
     * `tenant_id` — a settings write is always tenant-scoped.
     *
     * @param array<string, mixed> $attributes Raw column => value (JSON columns already encoded).
     */
    public static function draft(SettingsSection $section, array $attributes): self
    {
        $tenantId = isset($attributes['tenant_id']) ? (string) $attributes['tenant_id'] : '';
        if ($tenantId === '') {
            throw new \DomainException('TenantSettings requires a tenant id.');
        }

        $e = new self();
        $e->section = $section;
        $e->forceFill($attributes);
        $e->syncOriginal();

        return $e;
    }

    public function section(): SettingsSection { return $this->section; }
    public function table(): string            { return $this->section->table(); }
    public function tenantId(): string         { return $this->getString('tenant_id'); }
}
