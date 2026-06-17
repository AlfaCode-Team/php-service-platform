<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Persistence;

/**
 * SavepointGrammar — produces driver-correct SQL for nested transaction savepoints.
 *
 * Standard SQL (MySQL, PostgreSQL, SQLite) uses SAVEPOINT / RELEASE SAVEPOINT /
 * ROLLBACK TO SAVEPOINT. SQL Server uses its own SAVE TRANSACTION / ROLLBACK
 * TRANSACTION dialect and has no RELEASE equivalent.
 */
final class SavepointGrammar
{
    public function __construct(
        private readonly string $driver,
    ) {}

    /**
     * Whether this driver supports savepoint-based nested transactions.
     */
    public function supportsSavepoints(): bool
    {
        return \in_array($this->driver, ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true);
    }

    public function compileSavepoint(string $name): string
    {
        return $this->driver === 'sqlsrv'
            ? 'SAVE TRANSACTION ' . $this->escape($name)
            : 'SAVEPOINT ' . $this->escape($name);
    }

    /**
     * SQL Server has no RELEASE SAVEPOINT — releasing is a no-op there.
     */
    public function compileRelease(string $name): ?string
    {
        return $this->driver === 'sqlsrv'
            ? null
            : 'RELEASE SAVEPOINT ' . $this->escape($name);
    }

    public function compileRollbackTo(string $name): string
    {
        return $this->driver === 'sqlsrv'
            ? 'ROLLBACK TRANSACTION ' . $this->escape($name)
            : 'ROLLBACK TO SAVEPOINT ' . $this->escape($name);
    }

    /**
     * Generate the canonical savepoint identifier for a given transaction depth.
     */
    public function name(int $level): string
    {
        return 'gda_sp_' . $level;
    }

    private function escape(string $name): string
    {
        // Savepoint names are framework-generated (gda_sp_N); still guard the identifier.
        return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? $name;
    }
}
