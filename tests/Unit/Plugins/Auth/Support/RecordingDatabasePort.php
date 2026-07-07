<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Auth\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * DatabasePort test double that records writes and replays canned reads. Just
 * enough to exercise PersonalAccessTokenRepository from AuthService tests.
 */
final class RecordingDatabasePort implements DatabasePort
{
    /** @var list<array{sql:string,params:array}> */
    public array $executed = [];

    /** @var list<array<string,mixed>> rows returned by the next query() call */
    public array $queryRows = [];

    public function query(string $sql, array $params = []): array
    {
        return $this->queryRows;
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->queryRows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->executed[] = ['sql' => $sql, 'params' => $params];

        return 1;
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        return 0;
    }

    public function lastInsertId(?string $sequence = null): string { return '0'; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}
    public function inTransaction(): bool { return false; }
}
