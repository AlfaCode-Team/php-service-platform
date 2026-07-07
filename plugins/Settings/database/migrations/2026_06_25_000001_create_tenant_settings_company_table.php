<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Settings — central `tenant_settings_company` (CONTROL PLANE).
 *
 * OptionsDTO: company branding & identity. One row per tenant, keyed by
 * `tenant_id`. Runs ONLY on the central connection — never inside a tenant DB. Owned by the
 * Settings plugin; depends on the Tenancy `tenants` table (FK target) existing
 * first — earlier timestamps guarantee that ordering.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_settings_company')) {
            return;
        }

        $schema->create('tenant_settings_company', static function ($t) {
            $t->char('tenant_id', 31)->primary()->comment('owning tenant');

            $t->string('company_name', 128)->default('Acme Inc');
            $t->string('company_email', 191)->nullable();
            $t->string('company_phone', 32)->nullable();
            $t->string('company_phone_area', 16)->nullable();
            $t->string('company_address', 255)->nullable();
            $t->string('company_city', 64)->nullable();
            $t->string('company_region', 64)->nullable();
            $t->char('company_country', 2)->nullable();
            $t->string('company_timezone', 64)->default('UTC');
            $t->string('company_logo', 255)->nullable();
            $t->text('company_description')->nullable();
            $t->string('company_founder', 128)->nullable();
            $t->char('company_founded_year', 4)->nullable();
            $t->json('company_social_links')->nullable();
            $t->json('company_service_types')->nullable();

            $t->dateTime('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
            $t->comment('OptionsDTO: company branding & identity');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_settings_company');
    }
};
