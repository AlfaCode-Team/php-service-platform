<?php
declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PDO;
use PDOException;

/**
 * PdoDatabase — project-supplied DatabasePort adapter (PROJECT LAYER).
 *
 * The kernel defines the DatabasePort interface; the project provides this
 * implementation and binds it in bootstrap/app.php via ->withPorts([...]).
 *
 * Defaults to an in-memory SQLite connection so the framework boots with zero
 * external infrastructure. Point DB_DSN / DB_USERNAME / DB_PASSWORD at MySQL or
 * Postgres for production.
 *
 * Swoole note: one instance is created per worker process inside bootstrap/app.php
 * and lives for the worker's lifetime. PDO connections are not shared across
 * workers, so this is safe under OpenSwoole.
 */
final class PdoDatabase implements DatabasePort
{
    private PDO $pdo;

    public function __construct(
        string $dsn = 'sqlite::memory:',
        ?string $username = null,
        ?string $password = null,
    ) {
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
