<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Database;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * TransactionManager — thin, nesting-aware wrapper over DatabasePort transactions.
 *
 * Services use begin()/commit()/rollback() to bracket their unit of work. Nested
 * begin() calls increment a depth counter and only the outermost commit actually
 * commits, so composing services never double-commit or prematurely close a tx.
 *
 * Request-scoped: resolve from the ModuleContainer, never share across requests.
 */
final class TransactionManager
{
    private int $depth = 0;

    public function __construct(
        private readonly DatabasePort $db
    ) {}

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->db->beginTransaction();
        }
        $this->depth++;
    }

    public function commit(): void
    {
        if ($this->depth === 0) {
            throw new \LogicException('commit() called with no active transaction.');
        }
        $this->depth--;
        if ($this->depth === 0) {
            $this->db->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->depth === 0) {
            return;
        }
        // Any rollback unwinds the entire transaction stack.
        $this->depth = 0;
        if ($this->db->inTransaction()) {
            $this->db->rollback();
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }
}
