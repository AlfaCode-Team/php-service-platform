<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — central `tenant_hosts` registry (CONTROL PLANE).
 *
 * Maps a hostname (domain or subdomain) to the tenant that owns it, so an
 * incoming request can be resolved to a tenant BY ITS Host header alone — the
 * domain/subdomain IS the tenant identifier at the edge. A tenant may own many
 * hosts (apex + www + custom domains); each host belongs to exactly one tenant.
 * Resolution should match on a VERIFIED host only.
 *
 * Runs ONLY on the central connection — never inside a tenant database.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('tenant_hosts', static function ($t) {
            $t->id('host_id');
            $t->char('tenant_id', 31)->comment('owning tenant — resolves Host → tenant');
            $t->string('hostname', 191)->comment('FQDN domain or subdomain, lower-case, no port');
            $t->string('ip_address', 45)->nullable()->comment('expected A/AAAA target for verification');

            $t->tinyInteger('status')->unsigned()->default(0)
                ->comment('0=pending,1=verified,2=failed');
            $t->char('verification_token', 64)->comment('DNS/HTTP ownership challenge token');
            $t->timestamp('verified_at')->nullable();

            $t->boolean('is_primary')->default(false)
                ->comment('canonical host for this tenant (redirect target)');

            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();
            $t->softDeletes();

            $t->unique(['tenant_id', 'hostname'], 'uniq_tenant_hostname');
            $t->unique(['hostname'], 'uniq_hostname');
            $t->unique(['verification_token'], 'uniq_verification_token');
            $t->index(['tenant_id'], 'idx_tenant_id');
            $t->index(['status'], 'idx_status');

            $t->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenant_hosts');
    }
};
