<?php

declare(strict_types=1);

namespace Plugins\Commands\Configuration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\Commands\Exceptions\ConfigurationException;

final class EnvironmentConfigurationLoader
{
    public static function load(): array
    {
        $env = self::detectEnvironment();
        $configPath = self::resolveConfigPath($env);

        if (!is_file($configPath)) {
            throw ConfigurationException::fileNotFound($configPath);
        }

        try {
            $config = require $configPath;
        } catch (\Throwable $e) {
            throw ConfigurationException::loadFailed($configPath, $e);
        }

        if (!is_array($config)) {
            throw ConfigurationException::invalidStructure(
                "Configuration file {$configPath} must return an array"
            );
        }

        return ConfigurationValidator::validate($config);
    }

    private static function detectEnvironment(): string
    {
        return (string) (env('APP_ENV') ?: 'local');
    }

    private static function resolveConfigPath(string $env): string
    {
        // Try environment-specific config first
        $envConfigPath = Paths::config("environments/{$env}.php");
        if (is_file($envConfigPath)) {
            return $envConfigPath;
        }

        // Fall back to base config
        $baseConfigPath = Paths::config('let-migrate.php');
        if (is_file($baseConfigPath)) {
            return $baseConfigPath;
        }

        return $envConfigPath;  // Return env path so error message is clear
    }
}
