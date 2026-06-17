<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\Exceptions\ConnectionException;

/**
 * MultiDriverDatabaseAdapter — the kernel DatabasePort implementation.
 *
 * Wraps a single PDO connection with enterprise-grade behaviour:
 *
 *   • Lazy connection      — PDO is created on first use, not at construction,
 *                            so booting a module that never queries costs nothing.
 *   • Auto-reconnect       — a dropped connection ("server has gone away") is
 *                            transparently re-established outside of a transaction.
 *   • Nested transactions  — begin/commit/rollback nest correctly via SAVEPOINTs;
 *                            only the outermost level touches the real transaction.
 *   • Query observability   — optional PSR-3 logger records every statement and
 *                            flags slow queries above a configurable threshold.
 *   • Vendor isolation     — every \PDOException is translated to ConnectionException.
 *
 * Implements DatabasePort — repositories depend on the interface, never this class.
 */
final class MultiDriverDatabaseAdapter implements DatabasePort
{
    private ?PDO $pdo = null;

    /** Current transaction nesting depth (0 = no active transaction). */
    private int $transactionLevel = 0;

    private readonly SavepointGrammar $savepoints;

    public function __construct(
        private readonly DatabaseConfigurationContract $config,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $logQueries = false,
        private readonly float $slowQueryThresholdMs = 200.0,
    ) {
        $this->savepoints = new SavepointGrammar($config->driver());
    }

    // ───────────────────────────────────────── connection lifecycle ─────────

    /**
     * Return the live PDO handle, connecting lazily on first access.
     *
     * @throws ConnectionException
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        return $this->pdo;
    }

    /**
     * @throws ConnectionException
     */
    private function connect(): void
    {
        try {
            $this->pdo = new PDO(
                $this->config->dsn(),
                $this->config->username(),
                $this->config->password(),
                $this->config->pdoOptions(),
            );

            foreach ($this->config->initStatements() as $statement) {
                $this->pdo->exec($statement);
            }
        } catch (PDOException $e) {
            $this->pdo = null;
            throw ConnectionException::connectionFailed(
                $this->config->driver(),
                $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * Drop and rebuild the underlying connection. Resets transaction state.
     *
     * @throws ConnectionException
     */
    public function reconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
        $this->connect();
    }

    /**
     * Lightweight health check — issues a trivial round-trip to the server.
     */
    public function ping(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Deterministically close the connection, dropping the PDO handle and any
     * in-flight transaction state. The next operation reconnects lazily. Used by
     * the connection pool to release sockets without waiting for GC.
     */
    public function close(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }

    // ───────────────────────────────────────────────────── reads/writes ─────

    public function query(string $sql, array $params = []): array
    {
        return $this->run('query', $sql, $params, static fn (PDOStatement $s): array => $s->fetchAll());
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->run('query', $sql, $params, static function (PDOStatement $s): ?array {
            $row = $s->fetch();
            return $row === false ? null : $row;
        });
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run('execute', $sql, $params, static fn (PDOStatement $s): int => $s->rowCount());
    }

    /**
     * Execute a prepared statement and project the result via $reader.
     *
     * Centralises preparation, parameter binding, timing, logging, reconnect
     * detection and error translation for every query path.
     *
     * @template T
     * @param callable(PDOStatement): T $reader
     * @return T
     * @throws ConnectionException
     */
    private function run(string $operation, string $sql, array $params, callable $reader): mixed
    {
        $startedAt = microtime(true);

        try {
            $stmt = $this->pdo()->prepare($sql);
            $stmt->execute($params);
            $result = $reader($stmt);
            $this->logQuery($sql, $params, $startedAt);

            return $result;
        } catch (PDOException $e) {
            // Outside a transaction a lost connection is safe to retry once.
            if ($this->transactionLevel === 0 && $this->isConnectionLost($e)) {
                $this->reconnect();

                try {
                    $stmt = $this->pdo()->prepare($sql);
                    $stmt->execute($params);
                    $result = $reader($stmt);
                    $this->logQuery($sql, $params, $startedAt);

                    return $result;
                } catch (PDOException $retry) {
                    $e = $retry;
                }
            }

            throw $operation === 'execute'
                ? ConnectionException::executionFailed($this->config->driver(), $sql, $e->getMessage(), $e)
                : ConnectionException::queryFailed($this->config->driver(), $sql, $e->getMessage(), $e);
        }
    }

    /**
     * Last auto-increment id. PostgreSQL requires the owning sequence name
     * (e.g. "users_id_seq") to resolve the value for a specific table.
     */
    public function lastInsertId(?string $sequence = null): string
    {
        try {
            return $sequence !== null
                ? (string) $this->pdo()->lastInsertId($sequence)
                : (string) $this->pdo()->lastInsertId();
        } catch (PDOException $e) {
            throw ConnectionException::queryFailed(
                $this->config->driver(),
                'lastInsertId',
                $e->getMessage(),
                $e,
            );
        }
    }

    // ──────────────────────────────────────────────── transactions ──────────

    public function beginTransaction(): void
    {
        try {
            if ($this->transactionLevel === 0) {
                $this->pdo()->beginTransaction();
            } elseif ($this->savepoints->supportsSavepoints()) {
                $this->pdo()->exec($this->savepoints->compileSavepoint(
                    $this->savepoints->name($this->transactionLevel),
                ));
            }

            $this->transactionLevel++;
        } catch (PDOException $e) {
            throw ConnectionException::transactionFailed(
                $this->config->driver(),
                'begin',
                $e->getMessage(),
                $e,
            );
        }
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            return;
        }

        try {
            if ($this->transactionLevel === 1) {
                $this->pdo()->commit();
            } elseif ($this->savepoints->supportsSavepoints()) {
                $release = $this->savepoints->compileRelease(
                    $this->savepoints->name($this->transactionLevel - 1),
                );
                if ($release !== null) {
                    $this->pdo()->exec($release);
                }
            }

            $this->transactionLevel--;
        } catch (PDOException $e) {
            throw ConnectionException::transactionFailed(
                $this->config->driver(),
                'commit',
                $e->getMessage(),
                $e,
            );
        }
    }

    public function rollback(): void
    {
        if ($this->transactionLevel === 0) {
            return;
        }

        try {
            if ($this->transactionLevel === 1) {
                $this->pdo()->rollBack();
            } elseif ($this->savepoints->supportsSavepoints()) {
                $this->pdo()->exec($this->savepoints->compileRollbackTo(
                    $this->savepoints->name($this->transactionLevel - 1),
                ));
            }

            $this->transactionLevel--;
        } catch (PDOException $e) {
            throw ConnectionException::transactionFailed(
                $this->config->driver(),
                'rollback',
                $e->getMessage(),
                $e,
            );
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Current nesting depth — exposed for diagnostics and tests.
     */
    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Run $work inside a transaction, committing on success and rolling back on
     * any throwable. Nests safely thanks to savepoints. Returns $work's value.
     *
     * @template T
     * @param callable(self): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->beginTransaction();

        try {
            $result = $work($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ──────────────────────────────────────────────────── introspection ─────

    public function driver(): string
    {
        return $this->config->driver();
    }

    public function configuration(): DatabaseConfigurationContract
    {
        return $this->config;
    }

    // ───────────────────────────────────────────────────────── internals ────

    /**
     * Detect "connection gone away" style errors that are safe to retry.
     */
    private function isConnectionLost(PDOException $e): bool
    {
        $sqlState = $e->getCode();

        // 08S01 / 08003 / 08006 are SQLSTATE connection-exception classes.
        if (\in_array((string) $sqlState, ['08S01', '08003', '08006', 'HY000'], true)) {
            $needles = [
                'server has gone away',
                'lost connection',
                'gone away',
                'broken pipe',
                'no connection to the server',
                'connection was killed',
                'ssl connection has been closed',
            ];
            $message = strtolower($e->getMessage());

            foreach ($needles as $needle) {
                if (str_contains($message, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function logQuery(string $sql, array $params, float $startedAt): void
    {
        if ($this->logger === null) {
            return;
        }

        $elapsedMs = (microtime(true) - $startedAt) * 1000.0;

        if ($elapsedMs >= $this->slowQueryThresholdMs) {
            $this->logger->warning('Slow database query', [
                'driver' => $this->config->driver(),
                'sql' => $sql,
                'bindings' => $params,
                'elapsed_ms' => round($elapsedMs, 3),
            ]);
        } elseif ($this->logQueries) {
            $this->logger->debug('Database query', [
                'driver' => $this->config->driver(),
                'sql' => $sql,
                'bindings' => $params,
                'elapsed_ms' => round($elapsedMs, 3),
            ]);
        }
    }

    /**
     * Releasing the adapter closes the PDO handle (PDO closes on last reference).
     */
    public function __destruct()
    {
        $this->close();
    }
}
