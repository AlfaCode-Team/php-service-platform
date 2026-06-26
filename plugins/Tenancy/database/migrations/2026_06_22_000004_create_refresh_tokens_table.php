<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — central `refresh_tokens` (CONTROL PLANE).
 *
 * Stateless access JWTs are short-lived (~15 min); this is their revocable
 * long-lived counterpart. Only the SHA-256 of the token is persisted. A refresh
 * issued at login carries no tenant; selecting a tenant can issue a
 * tenant-scoped refresh so the session survives token rotation within a tenant.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('refresh_tokens', static function ($t) {
            $t->id();
            $t->char('token_id', 31);
            $t->char('user_id', 31);
            $t->char('token_hash', 64)->comment('SHA-256 of the refresh token — never store raw');
            $t->char('tenant_id', 31)->nullable()->comment('null = not yet tenant-scoped');
            $t->string('device', 191)->nullable()->comment('UA / device label');
            $t->string('ip', 45)->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $t->unique(['token_id'], 'uniq_token_id');
            $t->unique(['token_hash'], 'uniq_token_hash');
            $t->index(['user_id', 'revoked_at'], 'idx_user_active');

            $t->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('refresh_tokens');
    }
};
