<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — central `user_tenants` membership (CONTROL PLANE).
 *
 * The many-to-many that lets one central user belong to many tenants, each with
 * a tenant-scoped role and status. This table is the authority the Auth layer
 * consults at tenant-selection time to mint a tenant-scoped Identity, and that
 * it re-checks per request so a revoked membership loses access immediately
 * (an unexpired JWT must not outlive the membership it claims).
 *
 * FKs reference the central `users` and `tenants` tables — both live in this
 * same central database, so the constraints are valid (FKs never span DBs).
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_tenants', static function ($t) {
            $t->id();
            $t->char('user_id', 31);
            $t->char('tenant_id', 31);
            $t->string('role', 32)->default('member')->comment('owner|admin|member|viewer');
            $t->tinyInteger('status')->unsigned()->default(1)
                ->comment('1=active,2=invited,3=suspended');
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();

            $t->unique(['user_id', 'tenant_id'], 'uniq_user_tenant');
            $t->index(['tenant_id', 'status'], 'idx_tenant_status'); // list members of a tenant
            $t->index(['user_id', 'status'], 'idx_user_status');     // list my tenants (login hot path)

            $t->foreign('user_id')->references('user_id')->on('users')->onDelete('cascade');
            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_tenants');
    }
};
