<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Settings — central `tenant_settings_contact` (CONTROL PLANE).
 *
 * OptionsDTO: contact form settings. One row per tenant, keyed by `tenant_id`.
 * Runs ONLY on the central connection — never inside a tenant DB. Owned by the
 * Settings plugin; depends on the Tenancy `tenants` table (FK target) existing
 * first — earlier timestamps guarantee that ordering.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_settings_contact')) {
            return;
        }

        $schema->create('tenant_settings_contact', static function ($t) {
            $t->char('tenant_id', 31)->primary()->comment('owning tenant');

            $t->string('contact_form_recipients', 255)->nullable();
            $t->string('contact_auto_reply_subject', 255)->nullable();
            $t->text('contact_auto_reply_message')->nullable();

            $t->dateTime('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
            $t->comment('OptionsDTO: contact form settings');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_settings_contact');
    }
};
