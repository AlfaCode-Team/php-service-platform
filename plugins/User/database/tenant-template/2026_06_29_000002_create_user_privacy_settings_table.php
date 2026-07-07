<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — create the TENANT-scoped `user_privacy_settings` table.
 *
 * One row per user per tenant (uniq_user_privacy). Applied per-tenant DB by
 * `tenant:migrate`. `user_id` is a soft reference to central `users.id` — no
 * cross-DB foreign key (see user_profiles migration for the rationale).
 *
 * Booleans are stored as tinyint(1); the Blueprint `boolean()` compiles to the
 * correct per-driver type.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_privacy_settings', static function ($t) {
            $t->id();

            $t->char('user_id', 31)
                ->comment('Soft ref to central users.user_id (ULID) — no cross-DB FK');

            $t->string('profile_visibility', 10)->default('public')
                ->comment('public|private|contacts');
            $t->boolean('show_phone')->default(true);
            $t->boolean('show_email')->default(false);
            $t->boolean('marketing_opt_in')->default(false);
            $t->boolean('analytics_opt_in')->default(true);

            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')
                ->onUpdateCurrentTimestamp();

            // One settings row per user — also the lookup index.
            $t->unique(['user_id'], 'uniq_user_privacy');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_privacy_settings');
    }
};
