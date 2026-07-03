<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — create the TENANT-scoped `user_notification_preferences` table.
 *
 * One row per user per tenant (uniq_user_notif_prefs). Applied per-tenant DB by
 * `tenant:migrate`. `user_id` is a soft reference to central `users.id` — no
 * cross-DB foreign key.
 *
 * A flat opt-in matrix of (channel × topic). Channels: push / email / sms.
 * Topics: messages, bookings, payments, reminders, promotions, security.
 * Defaults follow least-surprise: transactional topics on, promotions off,
 * security always on by default.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_notification_preferences', static function ($t) {
            $t->id();

            $t->char('user_id', 31)
                ->comment('Soft ref to central users.user_id (ULID) — no cross-DB FK');

            // Push channel.
            $t->boolean('push_messages')->default(true);
            $t->boolean('push_bookings')->default(true);
            $t->boolean('push_payments')->default(true);
            $t->boolean('push_reminders')->default(true);
            $t->boolean('push_promotions')->default(false);
            $t->boolean('push_security')->default(true);

            // Email channel.
            $t->boolean('email_messages')->default(false);
            $t->boolean('email_bookings')->default(true);
            $t->boolean('email_payments')->default(true);
            $t->boolean('email_reminders')->default(false);
            $t->boolean('email_promotions')->default(false);
            $t->boolean('email_security')->default(true);

            // SMS channel.
            $t->boolean('sms_messages')->default(false);
            $t->boolean('sms_bookings')->default(true);
            $t->boolean('sms_payments')->default(true);
            $t->boolean('sms_reminders')->default(false);
            $t->boolean('sms_promotions')->default(false);
            $t->boolean('sms_security')->default(true);

            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')
                ->onUpdateCurrentTimestamp();

            $t->unique(['user_id'], 'uniq_user_notif_prefs');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_notification_preferences');
    }
};
