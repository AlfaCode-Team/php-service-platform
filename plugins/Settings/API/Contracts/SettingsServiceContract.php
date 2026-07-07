<?php

declare(strict_types=1);

namespace Plugins\Settings\API\Contracts;

use Plugins\Settings\API\DTOs\CompanySettingsDTO;
use Plugins\Settings\API\DTOs\ContactSettingsDTO;
use Plugins\Settings\API\DTOs\EmailProviderSettingsDTO;
use Plugins\Settings\API\DTOs\EmailSettingsDTO;
use Plugins\Settings\API\DTOs\SystemSettingsDTO;

/**
 * Published contract for per-tenant settings (one row per `tenant_id` across
 * the central `tenant_settings_*` tables).
 *
 * Each getter returns a fully-defaulted DTO when no row exists yet, so callers
 * never deal with nulls. Each save performs an idempotent upsert.
 */
interface SettingsServiceContract
{
    public function company(string $tenantId): CompanySettingsDTO;

    public function saveCompany(CompanySettingsDTO $settings): CompanySettingsDTO;

    /** Persist a newly-stored logo path onto the company settings. */
    public function updateCompanyLogo(string $tenantId, string $logoPath): CompanySettingsDTO;

    /** Clear the company logo path (the stored blob is deleted by the caller). */
    public function removeCompanyLogo(string $tenantId): CompanySettingsDTO;

    public function contact(string $tenantId): ContactSettingsDTO;

    public function saveContact(ContactSettingsDTO $settings): ContactSettingsDTO;

    public function email(string $tenantId): EmailSettingsDTO;

    public function saveEmail(EmailSettingsDTO $settings): EmailSettingsDTO;

    public function emailProviders(string $tenantId): EmailProviderSettingsDTO;

    public function saveEmailProviders(EmailProviderSettingsDTO $settings): EmailProviderSettingsDTO;

    public function system(string $tenantId): SystemSettingsDTO;

    public function saveSystem(SystemSettingsDTO $settings): SystemSettingsDTO;
}
