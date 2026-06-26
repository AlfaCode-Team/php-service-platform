<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — central `tenant_invitations` (CONTROL PLANE).
 *
 * Email-based onboarding. Bridges the gap between "invited" and "has an
 * account": membership (`user_tenants`) needs a user_id, invitations do not.
 * Accepting an invite converts it into a `user_tenants` row. Only the SHA-256 of
 * the token is stored — the raw token lives only in the emailed link.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('tenant_invitations', static function ($t) {
            $t->id();
            $t->char('invite_id', 31);
            $t->char('tenant_id', 31);
            $t->string('email', 150);
            $t->string('role', 32)->default('member')->comment('owner|admin|member|viewer');
            $t->char('token_hash', 64)->comment('SHA-256 of the invite token — never store raw');
            $t->char('invited_by', 31)->comment('central users.user_id of the inviter');
            $t->tinyInteger('status')->unsigned()->default(1)
                ->comment('1=pending,2=accepted,3=revoked,4=expired');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->unique(['invite_id'], 'uniq_invite_id');
            $t->unique(['token_hash'], 'uniq_token_hash');
            // One live invite per (tenant,email): enforced in the service, indexed here.
            $t->index(['tenant_id', 'email', 'status'], 'idx_tenant_email_status');
            $t->index(['email', 'status'], 'idx_email_status');

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_invitations');
    }
};
