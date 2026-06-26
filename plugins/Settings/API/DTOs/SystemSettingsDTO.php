<?php

declare(strict_types=1);

namespace Plugins\Settings\API\DTOs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use Plugins\Validation\Validator;

/**
 * OptionsDTO: security, performance, localisation, backup — mirrors
 * `tenant_settings_system`. Loaded once per session.
 */
final readonly class SystemSettingsDTO
{
    public function __construct(
        public string $tenantId,
        public bool $securityAlerts = true,
        public bool $twoFactorAuth = false,
        public bool $ssoEnabled = false,
        public bool $apiAccessEnabled = true,
        public bool $sslEnabled = true,
        public bool $customDomainEnabled = false,
        public ?string $customDomain = null,
        public bool $cacheEnabled = true,
        public bool $cdnEnabled = true,
        public bool $compressionEnabled = true,
        public bool $webhooksEnabled = true,
        public int $apiRateLimit = 1000,
        public string $defaultLanguage = 'en',
        public string $dateFormat = 'MM/DD/YYYY',
        public string $timeFormat = '12h',
        public float $exchangeRate = 1.0,
        public bool $autoBackupEnabled = true,
        public string $backupFrequency = 'daily',
        public int $retentionPeriod = 30,
    ) {}

    /** Hard-coded defaults for a tenant with no stored row yet. */
    public static function defaults(string $tenantId): self
    {
        return new self($tenantId);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            tenantId:            (string) $row['tenant_id'],
            securityAlerts:      (bool) ($row['security_alerts'] ?? true),
            twoFactorAuth:       (bool) ($row['two_factor_auth'] ?? false),
            ssoEnabled:          (bool) ($row['sso_enabled'] ?? false),
            apiAccessEnabled:    (bool) ($row['api_access_enabled'] ?? true),
            sslEnabled:          (bool) ($row['ssl_enabled'] ?? true),
            customDomainEnabled: (bool) ($row['custom_domain_enabled'] ?? false),
            customDomain:        self::str($row['custom_domain'] ?? null),
            cacheEnabled:        (bool) ($row['cache_enabled'] ?? true),
            cdnEnabled:          (bool) ($row['cdn_enabled'] ?? true),
            compressionEnabled:  (bool) ($row['compression_enabled'] ?? true),
            webhooksEnabled:     (bool) ($row['webhooks_enabled'] ?? true),
            apiRateLimit:        (int) ($row['api_rate_limit'] ?? 1000),
            defaultLanguage:     (string) ($row['default_language'] ?? 'en'),
            dateFormat:          (string) ($row['date_format'] ?? 'MM/DD/YYYY'),
            timeFormat:          (string) ($row['time_format'] ?? '12h'),
            exchangeRate:        (float) ($row['exchange_rate'] ?? 1.0),
            autoBackupEnabled:   (bool) ($row['auto_backup_enabled'] ?? true),
            backupFrequency:     (string) ($row['backup_frequency'] ?? 'daily'),
            retentionPeriod:     (int) ($row['retention_period'] ?? 30),
        );
    }

    /**
     * Build from request input merged over `$base` (the tenant's current stored
     * settings), so an absent field keeps its existing value — a partial update,
     * never a reset to defaults.
     */
    public static function fromRequest(Request $request, self $base): self
    {
        Validator::make($request->all(), [
            'security_alerts'       => 'nullable|boolean',
            'two_factor_auth'       => 'nullable|boolean',
            'sso_enabled'           => 'nullable|boolean',
            'api_access_enabled'    => 'nullable|boolean',
            'ssl_enabled'           => 'nullable|boolean',
            'custom_domain_enabled' => 'nullable|boolean',
            'custom_domain'         => 'nullable|string|max:191',
            'cache_enabled'         => 'nullable|boolean',
            'cdn_enabled'           => 'nullable|boolean',
            'compression_enabled'   => 'nullable|boolean',
            'webhooks_enabled'      => 'nullable|boolean',
            'api_rate_limit'        => 'nullable|integer|between:0,65535',
            'default_language'      => 'nullable|string|max:8',
            'date_format'           => 'nullable|string|max:32',
            'time_format'           => 'nullable|in:12h,24h',
            'exchange_rate'         => 'nullable|numeric|min:0',
            'auto_backup_enabled'   => 'nullable|boolean',
            'backup_frequency'      => 'nullable|in:hourly,daily,weekly,monthly',
            'retention_period'      => 'nullable|integer|between:1,65535',
        ])->validate();

        $d = $base;

        return new self(
            tenantId:            $base->tenantId,
            securityAlerts:      $request->has('security_alerts') ? $request->boolean('security_alerts') : $d->securityAlerts,
            twoFactorAuth:       $request->has('two_factor_auth') ? $request->boolean('two_factor_auth') : $d->twoFactorAuth,
            ssoEnabled:          $request->has('sso_enabled') ? $request->boolean('sso_enabled') : $d->ssoEnabled,
            apiAccessEnabled:    $request->has('api_access_enabled') ? $request->boolean('api_access_enabled') : $d->apiAccessEnabled,
            sslEnabled:          $request->has('ssl_enabled') ? $request->boolean('ssl_enabled') : $d->sslEnabled,
            customDomainEnabled: $request->has('custom_domain_enabled') ? $request->boolean('custom_domain_enabled') : $d->customDomainEnabled,
            customDomain:        $request->input('custom_domain', $d->customDomain),
            cacheEnabled:        $request->has('cache_enabled') ? $request->boolean('cache_enabled') : $d->cacheEnabled,
            cdnEnabled:          $request->has('cdn_enabled') ? $request->boolean('cdn_enabled') : $d->cdnEnabled,
            compressionEnabled:  $request->has('compression_enabled') ? $request->boolean('compression_enabled') : $d->compressionEnabled,
            webhooksEnabled:     $request->has('webhooks_enabled') ? $request->boolean('webhooks_enabled') : $d->webhooksEnabled,
            apiRateLimit:        $request->has('api_rate_limit') ? $request->integer('api_rate_limit') : $d->apiRateLimit,
            defaultLanguage:     $request->string('default_language') ?: $d->defaultLanguage,
            dateFormat:          $request->string('date_format') ?: $d->dateFormat,
            timeFormat:          $request->string('time_format') ?: $d->timeFormat,
            exchangeRate:        $request->has('exchange_rate') ? $request->float('exchange_rate') : $d->exchangeRate,
            autoBackupEnabled:   $request->has('auto_backup_enabled') ? $request->boolean('auto_backup_enabled') : $d->autoBackupEnabled,
            backupFrequency:     $request->string('backup_frequency') ?: $d->backupFrequency,
            retentionPeriod:     $request->has('retention_period') ? $request->integer('retention_period') : $d->retentionPeriod,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_id'             => $this->tenantId,
            'security_alerts'       => $this->securityAlerts,
            'two_factor_auth'       => $this->twoFactorAuth,
            'sso_enabled'           => $this->ssoEnabled,
            'api_access_enabled'    => $this->apiAccessEnabled,
            'ssl_enabled'           => $this->sslEnabled,
            'custom_domain_enabled' => $this->customDomainEnabled,
            'custom_domain'         => $this->customDomain,
            'cache_enabled'         => $this->cacheEnabled,
            'cdn_enabled'           => $this->cdnEnabled,
            'compression_enabled'   => $this->compressionEnabled,
            'webhooks_enabled'      => $this->webhooksEnabled,
            'api_rate_limit'        => $this->apiRateLimit,
            'default_language'      => $this->defaultLanguage,
            'date_format'           => $this->dateFormat,
            'time_format'           => $this->timeFormat,
            'exchange_rate'         => $this->exchangeRate,
            'auto_backup_enabled'   => $this->autoBackupEnabled,
            'backup_frequency'      => $this->backupFrequency,
            'retention_period'      => $this->retentionPeriod,
        ];
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        $row = $this->toArray();
        foreach ([
            'security_alerts', 'two_factor_auth', 'sso_enabled', 'api_access_enabled',
            'ssl_enabled', 'custom_domain_enabled', 'cache_enabled', 'cdn_enabled',
            'compression_enabled', 'webhooks_enabled', 'auto_backup_enabled',
        ] as $boolKey) {
            $row[$boolKey] = (int) $row[$boolKey];
        }

        return $row;
    }

    private static function str(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
