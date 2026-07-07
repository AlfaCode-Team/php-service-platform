<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * DatabasePort — the ONLY way any repository touches the database.
 * The kernel defines this interface. The project provides the implementation.
 * Modules NEVER import a concrete adapter — only this interface.
 */
interface DatabasePort
{
    public function query(string $sql, array $params = []): array;
    public function queryOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;

    /**
     * Portable, atomic INSERT-or-UPDATE.
     *
     * Repositories must NOT hand-write the dialect-specific upsert clause
     * (`ON DUPLICATE KEY UPDATE` on MySQL vs `ON CONFLICT … DO UPDATE` on
     * PostgreSQL/SQLite). The implementation compiles the correct grammar for
     * the underlying driver, so a single call works identically everywhere.
     *
     * @param string        $table           Target table.
     * @param array<string,mixed> $values     Column => value to insert (also bound for the update).
     * @param string[]      $conflictColumns Unique/PK columns that define a collision.
     * @param string[]|null $updateColumns   Columns to overwrite on conflict.
     *                                        Null = every non-conflict column.
     *                                        [] = do nothing on conflict (insert-if-absent).
     * @return int Affected row count.
     */
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int;

    /**
     * Last auto-increment id. PostgreSQL needs the owning sequence name to
     * resolve a specific table's value; MySQL/SQLite ignore the argument.
     */
    public function lastInsertId(?string $sequence = null): string;

    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}
