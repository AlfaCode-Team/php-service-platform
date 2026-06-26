<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Settings — central `tenant_settings_system` (CONTROL PLANE).
 *
 * OptionsDTO: security, performance, localisation, backup — loaded once per
 * session. One row per tenant, keyed by `tenant_id`. Runs ONLY on the central
 * connection — never inside a tenant DB.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_settings_system')) {
            return;
        }

        $schema->create('tenant_settings_system', static function ($t) {
            $t->char('tenant_id', 31)->primary()->comment('owning tenant');

            $t->boolean('security_alerts')->default(true);
            $t->boolean('two_factor_auth')->default(false);
            $t->boolean('sso_enabled')->default(false);
            $t->boolean('api_access_enabled')->default(true);
            $t->boolean('ssl_enabled')->default(true);
            $t->boolean('custom_domain_enabled')->default(false);
            $t->string('custom_domain', 191)->nullable();
            $t->boolean('cache_enabled')->default(true);
            $t->boolean('cdn_enabled')->default(true);
            $t->boolean('compression_enabled')->default(true);
            $t->boolean('webhooks_enabled')->default(true);
            $t->unsignedSmallInteger('api_rate_limit')->default(1000);

            $t->string('default_language', 8)->default('en');
            $t->string('date_format', 32)->default('MM/DD/YYYY');
            $t->string('time_format', 4)->default('12h');
            $t->decimal('exchange_rate', 12, 4)->default(1.0000);

            $t->boolean('auto_backup_enabled')->default(true);
            $t->string('backup_frequency', 16)->default('daily');
            $t->unsignedSmallInteger('retention_period')->default(30);

            $t->dateTime('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
            $t->comment('OptionsDTO: security, performance, localisation, backup — loaded once per session');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_settings_system');
    }
};
