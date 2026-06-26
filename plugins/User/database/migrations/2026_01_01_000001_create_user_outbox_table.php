<?php

declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

/**
 * Transactional outbox for the User domain.
 *
 * Integration events are written into this table INSIDE the same transaction
 * that mutates the user, then relayed to the EventBus by `user:outbox:relay`.
 * This guarantees at-least-once delivery even if the process dies between
 * commit and dispatch — the classic outbox pattern.
 */
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('user_outbox', static function ($t) {
            $t->id();

            $t->char('event_id', 36)->comment('UUID — idempotency key for consumers');
            $t->string('event_name', 100)->comment('e.g. user.registered');
            $t->string('event_version', 16)->default('1.0');
            $t->json('payload');

            $t->tinyInteger('status')->unsigned()->default(0)
                ->comment('0=pending,1=dispatched,2=failed');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();

            $t->timestamp('occurred_at');
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $t->unique(['event_id'], 'uniq_event_id');
            // Relay scans pending rows oldest-first.
            $t->index(['status', 'occurred_at'], 'idx_status_occurred');

            $t->engine('InnoDB');
            $t->charset('utf8mb4');
            $t->collation('utf8mb4_0900_ai_ci');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('user_outbox');
    }
};
