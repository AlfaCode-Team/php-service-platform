<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — create the TENANT-scoped `user_profiles` table.
 *
 * SCOPE: this lives in the User plugin's tenant-template, so it is applied to
 * each TENANT database by `tenant:migrate` (NOT the central DB). Identity itself
 * (`users`) is central; this is per-tenant presentation data for that identity.
 *
 * `user_id` is a SOFT reference to the central `users.id` (bigint unsigned). It
 * carries NO foreign key: the referenced row lives in a different (central)
 * database, so a cross-DB FK is impossible — integrity is enforced in the
 * service layer, never by the engine.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_profiles', static function ($t) {
            // One profile per user per tenant → user_id IS the primary key.
            // char(31) matches the central users.user_id ULID width.
            $t->char('user_id', 31)
                ->comment('Soft ref to central users.user_id (ULID) — no cross-DB FK');

            $t->string('first_name', 80)->nullable();
            $t->string('last_name', 80)->nullable();
            $t->string('avatar_url', 500)->nullable()
                ->comment('Absolute or storage-relative avatar URL');
            $t->string('timezone', 50)->default('UTC')
                ->comment('IANA tz name, e.g. Africa/Kampala');
            $t->char('locale', 5)->default('en_US')
                ->comment('ll_CC language tag');
            $t->string('phone', 15)->default('0700000000')
                ->comment('E.164-ish local number; default is a placeholder');

            // Touched on every write so callers can cache-bust on profile edits.
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')
                ->onUpdateCurrentTimestamp();

            $t->primary(['user_id'], 'pk_user_profiles');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_profiles');
    }
};
