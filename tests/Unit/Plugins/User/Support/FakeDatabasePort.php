<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/** No-op DatabasePort so a real TransactionManager can be used in tests. */
final class FakeDatabasePort implements DatabasePort
{
    private bool $inTx = false;
    public int $commits = 0;
    public int $rollbacks = 0;

    public function query(string $sql, array $params = []): array { return []; }
    public function queryOne(string $sql, array $params = []): ?array { return null; }
    public function execute(string $sql, array $params = []): int { return 0; }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return 0; }
    public function lastInsertId(?string $sequence = null): string { return '0'; }
    public function beginTransaction(): void { $this->inTx = true; }
    public function commit(): void { $this->inTx = false; $this->commits++; }
    public function rollback(): void { $this->inTx = false; $this->rollbacks++; }
    public function inTransaction(): bool { return $this->inTx; }
}
