<?php

declare(strict_types=1);

namespace Plugins\Voting\Infrastructure\Engine;

use AlfaCode\PulseEngine\Contract\DatabaseInterface;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * PulseDatabaseAdapter — bridges the kernel DatabasePort to pulse-engine's
 * DatabaseInterface.
 *
 * Lets pulse-engine repositories/services run against the project's configured
 * database connection. Parameter binding is preserved end-to-end; this adapter
 * never interpolates user input into SQL.
 */
final class PulseDatabaseAdapter implements DatabaseInterface
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->db->queryOne($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->db->query($sql, $params);
    }

    public function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        $this->db->execute($sql, $data);

        return (int) $this->db->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $setParts   = [];
        $bind       = [];
        foreach ($data as $column => $value) {
            $setParts[]          = $column . ' = :set_' . $column;
            $bind['set_' . $column] = $value;
        }

        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[]            = $column . ' = :where_' . $column;
            $bind['where_' . $column] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $setParts),
            implode(' AND ', $whereParts),
        );

        return $this->db->execute($sql, $bind);
    }

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        $this->db->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->db->inTransaction();
    }
}
