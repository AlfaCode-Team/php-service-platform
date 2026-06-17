<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Exceptions\ConnectionException;

#[CoversClass(ConnectionException::class)]
final class ConnectionExceptionTest extends TestCase
{
    public function test_connection_failed_carries_context(): void
    {
        $previous = new \PDOException('refused');
        $e = ConnectionException::connectionFailed('mysql', 'refused', $previous);

        $this->assertSame('mysql', $e->driver);
        $this->assertSame('connect', $e->operation);
        $this->assertSame($previous, $e->getPrevious());
        $this->assertStringContainsString('mysql', $e->getMessage());
    }

    public function test_connection_lost_operation(): void
    {
        $e = ConnectionException::connectionLost('pgsql', 'gone away', new \PDOException());

        $this->assertSame('connection_lost', $e->operation);
    }

    public function test_query_failed_includes_sql(): void
    {
        $e = ConnectionException::queryFailed('sqlite', 'SELECT 1', 'syntax', new \PDOException());

        $this->assertSame('query', $e->operation);
        $this->assertStringContainsString('SELECT 1', $e->getMessage());
    }

    public function test_execution_failed_operation(): void
    {
        $e = ConnectionException::executionFailed('sqlite', 'INSERT', 'fail', new \PDOException());

        $this->assertSame('execute', $e->operation);
    }

    public function test_transaction_failed_namespaces_operation(): void
    {
        $e = ConnectionException::transactionFailed('mysql', 'commit', 'deadlock', new \PDOException());

        $this->assertSame('transaction.commit', $e->operation);
    }

    public function test_unsupported_driver_lists_supported(): void
    {
        $e = ConnectionException::unsupportedDriver('oracle');

        $this->assertSame('oracle', $e->driver);
        $this->assertSame('resolve_driver', $e->operation);
        $this->assertStringContainsString('mysql', $e->getMessage());
    }

    public function test_unknown_connection_operation(): void
    {
        $e = ConnectionException::unknownConnection('replica');

        $this->assertSame('resolve_connection', $e->operation);
        $this->assertStringContainsString('replica', $e->getMessage());
    }

    public function test_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, ConnectionException::unsupportedDriver('x'));
    }
}
