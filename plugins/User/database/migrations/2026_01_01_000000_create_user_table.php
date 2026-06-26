<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * User — create the `users` table.
 *
 * Published to database/migrations/ on `hkm plugins enable User` and run
 * by `migrate:run`. On `hkm plugins disable User` (with unpublish) the
 * down() below is rolled back before the file is removed. Always write a
 * matching down() — the unpublish rollback depends on it.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('users', static function ($t) {
            $t->id();

            $t->char('user_id', 31)->comment('ULID public identifier');
            $t->string('username', 50);
            $t->string('email', 150);
            $t->char('password_hash', 60)->comment('bcrypt — always 60 chars');
            $t->char('remember_token', 64)->nullable()
                ->comment('SHA-256 of the actual token for "remember me" cookie');
            $t->string('auth_provider', 30)->default('local')
                ->comment('local|google|github|saml');
            $t->string('provider_subject', 191)->nullable()
                ->comment('sub/oid from the external IdP');
            $t->boolean('is_platform_admin')->default(false)
                ->comment('global super-admin');
            $t->timestamp('last_login_at')->nullable();
            $t->tinyInteger('status')->unsigned()->default(3)
                ->comment('1=active,2=inactive,3=pending');
            $t->unsignedInteger('version')->default(1)
                ->comment('Optimistic-lock version — bumped on every update');
            $t->timestamp('email_verified_at')->nullable()
                ->comment('Set when the user confirms their email');

            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')
                ->onUpdateCurrentTimestamp();
            $t->softDeletes();

            $t->unique(['user_id'], 'uniq_user_id');
            // Identity is global: one human = one account.
            $t->unique(['username'], 'uniq_username');
            $t->unique(['email'], 'uniq_email');
            $t->unique(['auth_provider', 'provider_subject'], 'uniq_provider_subject');
            $t->index(['status', 'deleted_at'], 'idx_status_del');
            $t->index(['remember_token'], 'idx_remember_token');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('users');
    }
};
