<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Settings — central `tenant_settings_email_providers` (CONTROL PLANE).
 *
 * OptionsDTO: third-party email provider credentials — loaded only when
 * provider != smtp. One row per tenant, keyed by `tenant_id`. Runs ONLY on the
 * central connection — never inside a tenant DB.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_settings_email_providers')) {
            return;
        }

        $schema->create('tenant_settings_email_providers', static function ($t) {
            $t->char('tenant_id', 31)->primary()->comment('owning tenant');

            $t->string('sendgrid_api_key', 255)->nullable();
            $t->string('mailgun_domain', 191)->nullable();
            $t->string('mailgun_api_key', 255)->nullable();
            $t->string('mailgun_region', 32)->nullable();
            $t->string('postmark_server_token', 255)->nullable();
            $t->string('aws_access_key_id', 128)->nullable();
            $t->string('aws_secret_access_key', 255)->nullable();
            $t->string('aws_region', 32)->nullable();

            $t->dateTime('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
            $t->comment('OptionsDTO: third-party email provider credentials — loaded only when provider != smtp');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_settings_email_providers');
    }
};
