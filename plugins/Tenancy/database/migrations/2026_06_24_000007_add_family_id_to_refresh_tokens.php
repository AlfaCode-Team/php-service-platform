<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Tenancy — add `family_id` to `refresh_tokens` for rotation-reuse detection.
 *
 * Every refresh token belongs to a FAMILY: the root token issued at login (or on
 * tenant selection) and every token derived from it by rotation share one
 * family_id. Presenting an already-revoked token (a replay of a stolen/captured
 * token) lets the service revoke the WHOLE family at once — the standard
 * refresh-token reuse-detection response.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        // Idempotent: tolerate a re-run where the column already exists (drift
        // from an interrupted enable/disable cycle).
        if (!$schema->hasTable('refresh_tokens') || $schema->hasColumn('refresh_tokens', 'family_id')) {
            return;
        }

        $schema->table('refresh_tokens', static function ($t) {
            $t->char('family_id', 31)->nullable()->after('token_id')
              ->comment('Rotation lineage — all tokens derived from one login share this');
            $t->index(['family_id'], 'idx_family');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Idempotent: only drop what is actually present so rollback never fails
        // when the schema and the migration log have drifted apart.
        if (!$schema->hasTable('refresh_tokens') || !$schema->hasColumn('refresh_tokens', 'family_id')) {
            return;
        }

        // Dropping the column also drops its index on MySQL/PostgreSQL/SQLite, so
        // we do NOT drop idx_family explicitly — that would fail if the index has
        // drifted out of existence while the column remains.
        $schema->table('refresh_tokens', static function ($t) {
            $t->dropColumn('family_id');
        });
    }
};
