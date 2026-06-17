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
    public function lastInsertId(): string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}
