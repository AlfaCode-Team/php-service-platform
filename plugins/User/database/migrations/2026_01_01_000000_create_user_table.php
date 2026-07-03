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
            // --- Primary key ------------------------------------------------
            // WHAT:  Auto-increment BIGINT surrogate key (`id`).
            // WHY:   Internal numeric PK keeps foreign keys, joins and indexes
            //        compact and fast; it is NEVER exposed to the outside world.
            // WHERE: Used internally by the repository for row identity and FK
            //        references from other central tables (e.g. user_tenants).
            //        External callers always use `user_id` instead.
            $t->id();

            // --- Public identifier ------------------------------------------
            // WHAT:  ULID string — the user's public, URL-safe identifier.
            // WHY:   ULIDs are sortable, collision-resistant and leak no row
            //        count (unlike the auto-increment id). Exposing the numeric
            //        PK would let outsiders guess record counts / enumerate.
            // WHERE: This is the value carried in JWT `sub` and Identity.userId;
            //        every API URL and cross-module reference uses it.
            $t->char('user_id', 31)->comment('ULID public identifier');

            // --- Login / display name ---------------------------------------
            // WHAT:  The chosen username (max 50 chars).
            // WHY:   Human-friendly handle for login + display. Capped at 50 to
            //        bound index size and keep the unique index lean.
            // WHERE: Read on registration and credential verification in
            //        UserService; shown in admin/user listings.
            $t->string('username', 50);

            // --- Email ------------------------------------------------------
            // WHAT:  The user's email address (max 150 chars).
            // WHY:   Primary contact + alternate login + recovery channel. 150
            //        comfortably fits real-world addresses while staying
            //        indexable.
            // WHERE: Used for verification emails, password reset, and as a
            //        unique login key in UserService.
            $t->string('email', 150);

            // --- Password hash ----------------------------------------------
            // WHAT:  bcrypt hash of the password — ALWAYS exactly 60 chars.
            // WHY:   Never store plaintext. CHAR(60) matches bcrypt's fixed
            //        output exactly (no wasted space, no truncation risk).
            //        Nullable-free: federated users still get a row, see below.
            // WHERE: Written by UserService on register/change-password;
            //        compared with a timing-safe verify + rehash-on-login.
            $t->char('password_hash', 60)->comment('bcrypt — always 60 chars');

            // --- Remember-me token ------------------------------------------
            // WHAT:  SHA-256 hash (64 hex chars) of the "remember me" cookie
            //        token. Nullable — only set when the user opts in.
            // WHY:   We store the HASH, never the raw token, so a DB leak can't
            //        be used to forge long-lived sessions. SHA-256 → 64 chars.
            // WHERE: Validated on auto-login from the persistent cookie; see
            //        idx_remember_token below for the lookup index.
            $t->char('remember_token', 64)->nullable()
                ->comment('SHA-256 of the actual token for "remember me" cookie');

            // --- Optimistic-lock version ------------------------------------
            // WHAT:  Monotonic version counter, starts at 1.
            // WHY:   Implements optimistic concurrency: an UPDATE includes the
            //        expected version; a mismatch means someone else wrote
            //        first → OptimisticLockException, no lost update.
            // WHERE: Bumped on every UserService update; checked in the WHERE
            //        clause of the update statement.
            $t->unsignedInteger('version')->default(1)
                ->comment('Optimistic-lock version — bumped on every update');

            // --- Email verified timestamp -----------------------------------
            // WHAT:  When the user confirmed ownership of their email.
            //        Nullable = not yet verified.
            // WHY:   Stores the proof-of-verification moment rather than a bare
            //        boolean, which doubles as an audit trail.
            // WHERE: Set when the emailed confirmation link is consumed; gates
            //        features that require a verified address.
            $t->timestamp('email_verified_at')->nullable()
                ->comment('Set when the user confirms their email');

            // --- Row timestamps ---------------------------------------------
            // WHAT:  created_at — set once at INSERT; updated_at — set at INSERT
            //        and auto-refreshed on every UPDATE.
            // WHY:   Standard auditing of when a row was created and last
            //        changed. DB-side defaults guarantee they are always
            //        populated even on raw writes. (On PostgreSQL LetMigrate
            //        emits a BEFORE UPDATE trigger for onUpdateCurrentTimestamp.)
            // WHERE: Read for sorting/auditing; never set by hand in app code.
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('updated_at')->default('CURRENT_TIMESTAMP')
                ->onUpdateCurrentTimestamp();

            // --- Soft delete ------------------------------------------------
            // WHAT:  Adds a nullable `deleted_at` column.
            // WHY:   Soft delete preserves identity history and FK integrity
            //        instead of hard-removing the row. A NULL means "live".
            // WHERE: Every repository query filters `deleted_at IS NULL`.
            $t->softDeletes();

            // --- Constraints & indexes --------------------------------------
            // uniq_user_id: the public ULID must be globally unique (it is the
            //   external identity) and this index also speeds id→row lookups.
            $t->unique(['user_id'], 'uniq_user_id');
            // Identity is global: one human = one account.
            // uniq_username / uniq_email: enforce one account per handle/email
            //   platform-wide (no per-tenant duplication) and back fast login
            //   lookups by username or email.
            $t->unique(['username'], 'uniq_username');
            $t->unique(['email'], 'uniq_email');
            // idx_remember_token: indexes the hashed cookie token so auto-login
            //   resolves the user in one indexed lookup.
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
