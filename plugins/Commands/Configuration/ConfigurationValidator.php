<?php

declare(strict_types=1);

namespace Plugins\Commands\Configuration;

use Plugins\Commands\Exceptions\ConfigurationException;

final class ConfigurationValidator
{
    private const VALID_DRIVERS = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];
    private const REQUIRED_CONN_KEYS = ['driver', 'host', 'database', 'username'];

    public static function validate(array $config): array
    {
        self::validateStructure($config);
        self::validateConnections($config);
        self::validatePaths($config);

        return $config;
    }

    private static function validateStructure(array $config): void
    {
        if (!isset($config['connections']) || !is_array($config['connections'])) {
            throw ConfigurationException::invalidStructure(
                'Configuration must contain "connections" array'
            );
        }

        if (empty($config['connections'])) {
            throw ConfigurationException::invalidStructure(
                'At least one connection must be configured'
            );
        }
    }

    private static function validateConnections(array $config): void
    {
        foreach ($config['connections'] as $name => $conn) {
            self::validateConnection((string) $name, $conn);
        }
    }

    private static function validateConnection(string $name, mixed $conn): void
    {
        if (!is_array($conn)) {
            throw ConfigurationException::invalidStructure(
                "Connection [$name] must be an array"
            );
        }

        foreach (self::REQUIRED_CONN_KEYS as $key) {
            if (!isset($conn[$key])) {
                throw ConfigurationException::missingConnection($key);
            }
        }

        if (!in_array($conn['driver'], self::VALID_DRIVERS, true)) {
            throw ConfigurationException::invalidDriver($conn['driver']);
        }
    }

    private static function validatePaths(array $config): void
    {
        if (empty($config['paths'] ?? [])) {
            throw ConfigurationException::emptyMigrationPaths();
        }

        if (!is_array($config['paths'])) {
            throw ConfigurationException::invalidStructure(
                'Migration paths must be an array'
            );
        }
    }
}
