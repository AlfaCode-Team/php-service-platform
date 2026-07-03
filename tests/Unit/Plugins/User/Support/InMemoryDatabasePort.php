<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * Minimal STATEFUL in-memory DatabasePort for repository round-trip tests.
 *
 * Supports exactly the operations the settings repository uses:
 *   - upsert($table, $values, ['user_id'])  → store row keyed by user_id
 *   - queryOne("… FROM <table> WHERE user_id = :id …", ['id' => …]) → that row
 *
 * It is intentionally tiny — it does not parse general SQL, only the
 * table name (via `FROM <table>`) and the `:id` parameter the settings repo
 * issues. Enough to verify the real repository <-> entity mapping end to end.
 */
final class InMemoryDatabasePort implements DatabasePort
{
    /** @var array<string, array<string, array<string,mixed>>> table => userId => row */
    private array $tables = [];

    public function query(string $sql, array $params = []): array
    {
        return [];
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $table = self::tableFrom($sql);
        $id    = (string) ($params['id'] ?? '');

        return $this->tables[$table][$id] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        $key = (string) ($values[$conflictColumns[0]] ?? '');
        $this->tables[$table][$key] = $values;

        return 1;
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return '0';
    }

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function inTransaction(): bool { return false; }

    private static function tableFrom(string $sql): string
    {
        return preg_match('/FROM\s+([A-Za-z0-9_]+)/i', $sql, $m) === 1 ? $m[1] : '';
    }
}
