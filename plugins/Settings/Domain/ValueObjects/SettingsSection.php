<?php

declare(strict_types=1);

namespace Plugins\Settings\Domain\ValueObjects;

/**
 * The fixed set of tenant settings sections. Each case owns the central table
 * that backs it, so only a known, validated identifier can ever reach a SQL
 * statement — no caller-supplied table name is interpolated.
 */
enum SettingsSection: string
{
    case Company        = 'company';
    case Contact        = 'contact';
    case Email          = 'email';
    case EmailProviders = 'email_providers';
    case System         = 'system';

    /** Central control-plane table backing this section. */
    public function table(): string
    {
        return 'tenant_settings_' . $this->value;
    }
}
