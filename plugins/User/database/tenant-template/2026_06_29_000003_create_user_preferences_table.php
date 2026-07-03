<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — create the TENANT-scoped `user_preferences` table.
 *
 * One row per user per tenant (uniq_user_preferences). Applied per-tenant DB by
 * `tenant:migrate`. `user_id` is a soft reference to central `users.id` — no
 * cross-DB foreign key.
 *
 * Holds locale/currency/theme plus accessibility toggles. Booleans are
 * tinyint(1) via `boolean()`.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_preferences', static function ($t) {
            $t->id();

            $t->char('user_id', 31)
                ->comment('Soft ref to central users.user_id (ULID) — no cross-DB FK');

            $t->string('language', 10)->default('en');
            $t->string('currency', 10)->default('UGX')
                ->comment('ISO 4217 display currency');
            $t->string('theme', 10)->default('system')
                ->comment('light|dark|system');

            // Accessibility toggles.
            $t->boolean('reduce_motion')->default(false);
            $t->boolean('larger_text')->default(false);
            $t->boolean('high_contrast')->default(false);
            $t->boolean('screen_reader_hints')->default(false);

            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')
                ->onUpdateCurrentTimestamp();

            $t->unique(['user_id'], 'uniq_user_preferences');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_preferences');
    }
};
