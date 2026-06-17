<?php

declare(strict_types=1);

namespace Plugins\Database\Infrastructure\Pool;

use Closure;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Exceptions\ConnectionException;

/**
 * ConnectionPool — a bounded, reusable set of physical database connections.
 *
 * Intended lifetime is **per worker process** (app-lifetime): build it once at
 * worker start and share it across every request handled by that worker. Each
 * request borrows exactly one connection (see PooledDatabaseAdapter) and returns
 * it on teardown, so connections are reused instead of reconnecting per request
 * and the worker never opens more than `maxConnections` sockets.
 *
 * Concurrency:
 *   • Under OpenSwoole with coroutines enabled, acquire() yields the scheduler
 *     while waiting for a free slot (cooperative, non-blocking).
 *   • Without coroutines (the default — sequential requests per worker), the
 *     wait loop simply spins with a short sleep; contention is rare because a
 *     single worker handles one request at a time.
 *
 * The pool stores idle connections; borrowed connections are tracked by object
 * id so a returned connection can be matched back to its slot.
 */
final class ConnectionPool
{
    /** @var list<PooledConnection> idle, ready-to-lend connections (LIFO). */
    private array $idle = [];

    /** @var array<int, PooledConnection> borrowed slots keyed by adapter object id. */
    private array $borrowed = [];

    private int $waiters = 0;

    private bool $closed = false;

    private bool $warmed = false;

    /**
     * @param Closure(): MultiDriverDatabaseAdapter $factory creates a fresh adapter
     */
    public function __construct(
        private readonly Closure $factory,
        private readonly PoolConfiguration $config,
        private readonly string $driver = 'unknown',
    ) {}

    /**
     * Open the minimum number of connections up front. Idempotent.
     */
    public function warmup(): void
    {
        if ($this->warmed) {
            return;
        }
        $this->warmed = true;

        for ($i = 0; $i < $this->config->minConnections; $i++) {
            $pc = $this->create();
            // Force a real connection so the first request does not pay for it.
            $pc->adapter->ping();
            $this->idle[] = $pc;
        }
    }

    /**
     * Borrow a connection, opening or waiting as needed.
     *
     * @throws ConnectionException when the pool is closed or stays exhausted
     *                             past the acquire timeout
     */
    public function acquire(): MultiDriverDatabaseAdapter
    {
        if ($this->closed) {
            throw ConnectionException::poolClosed($this->driver);
        }

        $deadline = microtime(true) + ($this->config->acquireTimeoutMs / 1000.0);

        while (true) {
            // 1. Reuse an idle connection, discarding any that are stale/dead.
            while (($pc = array_pop($this->idle)) !== null) {
                if ($this->isExpired($pc) || !$this->isValid($pc)) {
                    $this->discard($pc);
                    continue;
                }

                return $this->lend($pc);
            }

            // 2. Grow the pool while under the ceiling.
            if ($this->total() < $this->config->maxConnections) {
                return $this->lend($this->create());
            }

            // 3. Saturated — wait for a release or give up at the deadline.
            if (microtime(true) >= $deadline) {
                throw ConnectionException::poolExhausted(
                    $this->driver,
                    $this->config->maxConnections,
                    $this->config->acquireTimeoutMs,
                );
            }

            $this->waiters++;
            $this->sleepBriefly();
            $this->waiters--;
        }
    }

    /**
     * Return a previously-acquired connection to the pool.
     *
     * Foreign or double releases are ignored. A connection still inside a
     * transaction is rolled back before re-entering the pool so the next
     * borrower receives a clean session.
     */
    public function release(MultiDriverDatabaseAdapter $adapter): void
    {
        $id = spl_object_id($adapter);
        $pc = $this->borrowed[$id] ?? null;

        if ($pc === null) {
            return;
        }

        unset($this->borrowed[$id]);

        if ($this->closed || $this->isExpired($pc)) {
            $this->discard($pc);
            return;
        }

        try {
            while ($adapter->inTransaction()) {
                $adapter->rollback();
            }
        } catch (\Throwable) {
            // A connection we cannot clean up is not safe to reuse.
            $this->discard($pc);
            return;
        }

        $pc->touch();
        $this->idle[] = $pc;
    }

    /**
     * Permanently close the pool and drop every connection. Borrowed
     * connections are dropped as they return.
     */
    public function close(): void
    {
        $this->closed = true;
        $this->idle = [];
        $this->borrowed = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Live counters for health endpoints and tests.
     *
     * @return array{idle:int, active:int, total:int, max:int, min:int, waiters:int, closed:bool}
     */
    public function stats(): array
    {
        return [
            'idle' => count($this->idle),
            'active' => count($this->borrowed),
            'total' => $this->total(),
            'max' => $this->config->maxConnections,
            'min' => $this->config->minConnections,
            'waiters' => $this->waiters,
            'closed' => $this->closed,
        ];
    }

    // ─────────────────────────────────────────────────────────── internals ──

    private function total(): int
    {
        return count($this->idle) + count($this->borrowed);
    }

    private function create(): PooledConnection
    {
        return new PooledConnection(($this->factory)(), microtime(true));
    }

    private function lend(PooledConnection $pc): MultiDriverDatabaseAdapter
    {
        $this->borrowed[spl_object_id($pc->adapter)] = $pc;

        return $pc->adapter;
    }

    private function discard(PooledConnection $pc): void
    {
        // Close deterministically rather than waiting for the GC to drop the
        // last reference — pooled sockets should be freed promptly.
        $pc->adapter->close();
    }

    private function isExpired(PooledConnection $pc): bool
    {
        if ($this->config->maxLifetimeSec > 0 && $pc->ageSeconds() > $this->config->maxLifetimeSec) {
            return true;
        }

        return $this->config->idleTimeoutSec > 0 && $pc->idleSeconds() > $this->config->idleTimeoutSec;
    }

    private function isValid(PooledConnection $pc): bool
    {
        return !$this->config->validateOnAcquire || $pc->adapter->ping();
    }

    /**
     * Yield to the OpenSwoole scheduler when in a coroutine; otherwise spin-wait.
     */
    private function sleepBriefly(): void
    {
        if (\class_exists('\OpenSwoole\Coroutine') && \OpenSwoole\Coroutine::getCid() > 0) {
            \OpenSwoole\Coroutine::usleep(1000);
            return;
        }

        if (\class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::usleep(1000);
            return;
        }

        usleep(1000);
    }
}
