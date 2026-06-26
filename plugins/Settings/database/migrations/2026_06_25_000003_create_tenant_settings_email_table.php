<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Settings — central `tenant_settings_email` (CONTROL PLANE).
 *
 * OptionsDTO: email transport, behaviour & templates. One row per tenant, keyed
 * by `tenant_id`. Runs ONLY on the central connection — never inside a tenant DB. Owned by the
 * Settings plugin; depends on the Tenancy `tenants` table (FK target) existing
 * first — earlier timestamps guarantee that ordering.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_settings_email')) {
            return;
        }

        $schema->create('tenant_settings_email', static function ($t) {
            $t->char('tenant_id', 31)->primary()->comment('owning tenant');

            $t->string('email_provider', 32)->default('smtp')
                ->comment('smtp|sendgrid|mailgun|ses|postmark');
            $t->string('smtp_host', 128)->nullable();
            $t->unsignedSmallInteger('smtp_port')->default(587);
            $t->string('smtp_username', 191)->nullable();
            $t->string('smtp_password', 255)->nullable();
            $t->enum('smtp_encryption', ['none', 'ssl', 'tls'])->default('tls');

            $t->string('sender_name', 128)->nullable();
            $t->string('sender_email', 191)->nullable();
            $t->string('email_reply_to', 191)->nullable();
            $t->boolean('email_reply_to_enabled')->default(false);
            $t->boolean('email_test_mode')->default(false);
            $t->boolean('email_bounce_handling')->default(false);
            $t->boolean('email_unsubscribe_header')->default(false);
            $t->boolean('email_tracking_enabled')->default(false);
            $t->boolean('email_archive_enabled')->default(false);

            $t->unsignedSmallInteger('email_batch_size')->default(100);
            $t->unsignedTinyInteger('email_retry_attempts')->default(3);
            $t->unsignedSmallInteger('email_rate_limit')->default(100);
            $t->text('email_footer')->nullable();
            $t->json('email_templates')->nullable();
            $t->json('email_notifications')->nullable();

            $t->dateTime('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
            $t->comment('OptionsDTO: email transport, behaviour & templates');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_settings_email');
    }
};
