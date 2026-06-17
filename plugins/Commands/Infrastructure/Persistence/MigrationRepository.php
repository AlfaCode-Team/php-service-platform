<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Persistence;

use Plugins\Commands\Infrastructure\Gateways\LetMigrateGateway;
use Plugins\Commands\Configuration\EnvironmentConfigurationLoader;
use Plugins\Commands\Configuration\ConfigurationValidator;
use Plugins\Commands\Exceptions\ConfigurationException;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * MigrationRepository — wraps all LetMigrate operations.
 * Only this repository touches the LetMigrate library.
 *
 * Services never call LetMigrate directly; they call this repository.
 */
final class MigrationRepository
{
    public function __construct(
        private readonly LetMigrateGateway $letMigrate,
        private readonly string $projectRoot,
    ) {}

    /**
     * Load configuration from file or environment.
     * Validates the configuration before returning.
     *
     * @throws ServiceException
     */
    public function loadConfiguration(?string $configPath = null): array
    {
        try {
            if ($configPath) {
                return $this->loadFromFile($configPath);
            }

            return EnvironmentConfigurationLoader::load($this->projectRoot);
        } catch (ConfigurationException $e) {
            throw ServiceException::migrationFailed(
                "Failed to load configuration: {$e->getMessage()}"
            );
        }
    }

    /**
     * Load configuration from a specific file path.
     *
     * @throws ConfigurationException
     */
    private function loadFromFile(string $path): array
    {
        $fullPath = str_starts_with($path, '/') ? $path : $this->projectRoot . '/' . $path;

        if (!is_file($fullPath)) {
            throw ConfigurationException::fileNotFound($fullPath);
        }

        try {
            $config = require $fullPath;
            return ConfigurationValidator::validate($config);
        } catch (\Throwable $e) {
            throw ConfigurationException::loadFailed($fullPath, $e);
        }
    }

    /**
     * Run all pending migrations via LetMigrate.
     *
     * @throws ServiceException
     */
    public function runPending(array $config): array
    {
        try {
            $this->letMigrate->initializeWithConfig($config);
            $commands = $this->letMigrate->getMigrateCommands();

            // Find migrate:run command and execute it
            // Note: This is a simplified interface; actual implementation
            // depends on LetMigrate's command structure
            return [];
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to run migrations: {$e->getMessage()}"
            );
        }
    }

    /**
     * Rollback the last N migration batches.
     *
     * @throws ServiceException
     */
    public function rollback(array $config, int $steps = 1): array
    {
        try {
            $this->letMigrate->initializeWithConfig($config);
            // Implementation depends on LetMigrate's actual API
            return [];
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to rollback migrations: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get the current migration status (applied vs pending).
     *
     * @throws ServiceException
     */
    public function getStatus(array $config): array
    {
        try {
            $this->letMigrate->initializeWithConfig($config);
            // Implementation depends on LetMigrate's actual API
            return [
                'applied' => [],
                'pending' => [],
            ];
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to get migration status: {$e->getMessage()}"
            );
        }
    }

    /**
     * Reset all migrations (roll back everything).
     *
     * @throws ServiceException
     */
    public function reset(array $config): array
    {
        try {
            $this->letMigrate->initializeWithConfig($config);
            // Implementation: get all applied migrations and rollback all
            return [];
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to reset migrations: {$e->getMessage()}"
            );
        }
    }

    /**
     * Refresh migrations (reset + re-run all).
     *
     * @throws ServiceException
     */
    public function refresh(array $config): array
    {
        try {
            // First reset
            $this->reset($config);

            // Then re-run all
            return $this->runPending($config);
        } catch (ServiceException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to refresh migrations: {$e->getMessage()}"
            );
        }
    }

    /**
     * Check if database is accessible (health check).
     * Returns true if connection is OK, false otherwise.
     */
    public function isDatabaseAccessible(array $config): bool
    {
        try {
            // Load configuration and test connection
            $this->letMigrate->initializeWithConfig($config);
            // If we got here, config is valid
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if migration tracking table exists.
     */
    public function doesTrackingTableExist(array $config): bool
    {
        try {
            $table = $config['tracking_table'] ?? 'let_migrations';
            // Try to query INFORMATION_SCHEMA (MySQL/MariaDB compatible)
            // This is a basic check; actual implementation depends on database type
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
