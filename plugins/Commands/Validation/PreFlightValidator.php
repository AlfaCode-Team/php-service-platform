<?php

declare(strict_types=1);

namespace Plugins\Commands\Validation;

use Plugins\Commands\Application\Services\CommandsInfrastructureService;

final class PreFlightValidator
{
    private array $errors = [];
    private array $warnings = [];

    public function __construct(
        private readonly CommandsInfrastructureService $infrastructure,
    ) {}

    public function validate(array $config): PreFlightReport
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateDatabaseConnection($config);
        $this->validateMigrationPaths($config);
        $this->validateTrackingTable($config);

        return new PreFlightReport(
            valid: empty($this->errors),
            errors: $this->errors,
            warnings: $this->warnings
        );
    }

    private function validateDatabaseConnection(array $config): void
    {
        // Test the default connection
        $conn = $config['connections']['default'] ?? null;
        if (!$conn) {
            $this->errors[] = 'No default database connection configured';
            return;
        }

        // Check if database is accessible
        if (!$this->infrastructure->isDatabaseAccessible($config)) {
            $this->errors[] = 'Database connection test failed';
        }
    }

    private function validateMigrationPaths(array $config): void
    {
        $paths = $config['paths'] ?? [];

        if (empty($paths)) {
            $this->errors[] = 'No migration paths configured';
            return;
        }

        foreach ($paths as $path) {
            if (!is_dir($path)) {
                $this->warnings[] = "Migration path does not exist: {$path}";
            }

            if (!is_readable($path)) {
                $this->errors[] = "Migration path is not readable: {$path}";
            }
        }
    }

    private function validateTrackingTable(array $config): void
    {
        $table = $config['tracking_table'] ?? 'let_migrations';

        // Check if tracking table exists
        if (!$this->infrastructure->doesTrackingTableExist($config)) {
            $this->warnings[] = "Migration tracking table '{$table}' does not exist (will be created)";
        }
    }
}

final class PreFlightReport
{
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function hasIssues(): bool
    {
        return !empty($this->errors) || !empty($this->warnings);
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function getWarningCount(): int
    {
        return count($this->warnings);
    }
}
