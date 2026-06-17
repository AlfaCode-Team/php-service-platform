<?php

declare(strict_types=1);

namespace Tests\Unit\Database\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

#[CoversClass(MultiDriverDatabaseAdapter::class)]
final class QueryLoggingTest extends TestCase
{
    public function test_no_logging_when_logger_absent(): void
    {
        $db = new MultiDriverDatabaseAdapter(new SQLiteConfiguration(':memory:'));

        // Simply must not error without a logger.
        $db->query('SELECT 1');
        $this->assertTrue(true);
    }

    public function test_debug_logging_when_enabled(): void
    {
        $logger = $this->spyLogger();
        $db = new MultiDriverDatabaseAdapter(
            config: new SQLiteConfiguration(':memory:'),
            logger: $logger,
            logQueries: true,
        );

        $db->query('SELECT 1');

        $debugEntries = array_filter($logger->records, static fn ($r) => $r['level'] === 'debug');
        $this->assertNotEmpty($debugEntries);
        $entry = array_values($debugEntries)[0];
        $this->assertSame('sqlite', $entry['context']['driver']);
        $this->assertArrayHasKey('elapsed_ms', $entry['context']);
    }

    public function test_no_debug_logging_when_disabled(): void
    {
        $logger = $this->spyLogger();
        $db = new MultiDriverDatabaseAdapter(
            config: new SQLiteConfiguration(':memory:'),
            logger: $logger,
            logQueries: false,
        );

        $db->query('SELECT 1');

        $debugEntries = array_filter($logger->records, static fn ($r) => $r['level'] === 'debug');
        $this->assertEmpty($debugEntries);
    }

    public function test_slow_query_logged_as_warning_regardless_of_flag(): void
    {
        $logger = $this->spyLogger();
        // Threshold of 0ms guarantees every query counts as "slow".
        $db = new MultiDriverDatabaseAdapter(
            config: new SQLiteConfiguration(':memory:'),
            logger: $logger,
            logQueries: false,
            slowQueryThresholdMs: 0.0,
        );

        $db->query('SELECT 1');

        $warnings = array_filter($logger->records, static fn ($r) => $r['level'] === 'warning');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Slow database query', array_values($warnings)[0]['message']);
    }

    private function spyLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
