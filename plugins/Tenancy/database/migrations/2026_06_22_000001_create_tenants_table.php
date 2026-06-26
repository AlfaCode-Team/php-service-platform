<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — central `tenants` registry (CONTROL PLANE).
 *
 * Source of truth that maps a tenant_id to the connection coordinates of its
 * isolated database. Runs ONLY on the central connection — never inside a
 * tenant database. The db_password_enc column stores the connection password
 * ENCRYPTED via EncryptionPort; plaintext credentials must never land here.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('tenants', static function ($t) {
            $t->id();
            $t->char('tenant_id', 31)->comment('UUID/ULID public identifier');
            $t->string('name', 120);
            $t->string('slug', 63)->comment('DNS/db-safe; ^[a-z0-9-]+$');

            // Connection coordinates — many-DBs-one-server AND cross-server sharding.
            $t->string('db_driver', 16)->default('mysql');
            $t->string('db_host', 191);
            $t->unsignedSmallInteger('db_port')->default(3306);
            $t->string('db_name', 64)->comment('physical database, e.g. tnt_acme');
            $t->string('db_username', 64);
            $t->text('db_password_enc')->comment('encrypted via EncryptionPort — NEVER plaintext');
            $t->string('db_shard', 32)->nullable()->comment('logical shard/cluster id for ops');

            $t->tinyInteger('status')->unsigned()->default(2)
                ->comment('1=active,2=provisioning,3=suspended,4=deleted');
            $t->unsignedInteger('schema_version')->default(0)
                ->comment('last applied tenant migration batch');

            // Optional subscription metadata.
            $t->string('plan', 32)->default('free');
            $t->timestamp('trial_ends_at')->nullable();
            $t->json('settings')->nullable();

            $t->unsignedInteger('version')->default(1)->comment('optimistic-lock version');
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')->onUpdateCurrentTimestamp();
            $t->softDeletes();

            $t->unique(['tenant_id'], 'uniq_tenant_id');
            $t->unique(['slug'], 'uniq_slug');
            $t->index(['status'], 'idx_status');
            $t->index(['db_shard', 'status'], 'idx_shard_status');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
            $t->rowFormat('DYNAMIC');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('tenants');
    }
};
