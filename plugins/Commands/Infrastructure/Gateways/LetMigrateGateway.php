<?php

declare(strict_types=1);

namespace Plugins\Commands\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Commands\Migrate\CliCommandFactory;
use Plugins\Commands\Exceptions\ServiceException;

/**
 * LetMigrateGateway — wraps LetMigrate's CliCommandFactory.
 * This gateway loads configuration and provides LetMigrate commands to services.
 * Only this gateway touches the external LetMigrate library.
 */
final class LetMigrateGateway
{
    private ?CliCommandFactory $factory = null;

    /**
     * Initialize with configuration.
     * Configuration can be provided upfront or deferred until first use.
     *
     * @throws ServiceException
     */
    public function initializeWithConfig(?array $config): void
    {
        try {
            $this->factory = CliCommandFactory::fromConfig($config);
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to initialize LetMigrate: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get the CliCommandFactory (creates it if needed with null config).
     */
    private function factory(): CliCommandFactory
    {
        if ($this->factory === null) {
            $this->factory = CliCommandFactory::fromConfig(null);
        }
        return $this->factory;
    }

    /**
     * Get all available migration commands.
     *
     * @return array<string, object> Command class name -> instance
     */
    public function getMigrationCommands(): array
    {
        try {
            return $this->factory()->all();
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to load migration commands: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get only the core migrate:* commands.
     */
    public function getMigrateCommands(): array
    {
        try {
            return $this->factory()->migrate();
        } catch (\Throwable $e) {
            throw ServiceException::migrationFailed(
                "Failed to load migrate commands: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get the underlying configuration (for inspection).
     */
    public function getConfig(): ?array
    {
        return $this->factory()->config();
    }
}
