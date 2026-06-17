# Enterprise Implementation Guide

Quick-start guide for implementing critical improvements. **Estimated effort: 8 hours.**

---

## Priority 1: Configuration Validation (2 hours)

### Step 1: Create Validator
```php
// src/Kernel/Commands/Exceptions/ConfigurationException.php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Exceptions;

final class ConfigurationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $context = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidDriver(string $driver): self
    {
        return new self(
            "Invalid database driver: {$driver}. Supported: mysql, pgsql, sqlite, sqlsrv",
            context: 'database.driver'
        );
    }

    public static function missingConnection(string $key): self
    {
        return new self(
            "Required connection key missing: {$key}",
            context: 'database.connection'
        );
    }

    public static function emptyMigrationPaths(): self
    {
        return new self(
            'At least one migration path must be configured',
            context: 'migrations.paths'
        );
    }
}

// src/Kernel/Commands/Configuration/ConfigurationValidator.php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Configuration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Exceptions\ConfigurationException;

final class ConfigurationValidator
{
    private const VALID_DRIVERS = ['mysql', 'pgsql', 'sqlite', 'sqlsrv'];
    private const REQUIRED_CONN_KEYS = ['driver', 'host', 'database', 'username'];

    public static function validate(array $config): array
    {
        if (!isset($config['connections']) || !is_array($config['connections'])) {
            throw new ConfigurationException(
                'Configuration must contain "connections" array',
                context: 'structure'
            );
        }

        if (empty($config['connections'])) {
            throw new ConfigurationException(
                'At least one connection must be configured',
                context: 'connections'
            );
        }

        foreach ($config['connections'] as $name => $conn) {
            self::validateConnection((string) $name, $conn);
        }

        if (empty($config['paths'])) {
            throw ConfigurationException::emptyMigrationPaths();
        }

        return $config;
    }

    private static function validateConnection(string $name, mixed $conn): void
    {
        if (!is_array($conn)) {
            throw new ConfigurationException(
                "Connection [$name] must be an array",
                context: "connections.{$name}"
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
}
```

### Step 2: Update SystemCommandsProvider
```php
// Update src/Kernel/Commands/SystemCommandsProvider.php boot() method

public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
{
    $cli->command(ModuleAddCommand::class);
    $cli->command(ModuleRemoveCommand::class);

    try {
        $migrateConfig = $this->loadAndValidateConfiguration();
    } catch (ConfigurationException $e) {
        $this->handleConfigurationError($e);
        return;
    }

    $migrationFactory = MigrateFactory::fromConfig($migrateConfig);

    foreach ($migrationFactory->all() as $commandInstance) {
        $cli->command($commandInstance::class);
    }
}

private function loadAndValidateConfiguration(): array
{
    $configPath = __DIR__ . '/../../projects/admin/config/let-migrate.php';

    if (!is_file($configPath)) {
        throw new ConfigurationException(
            "Configuration file not found: {$configPath}",
            context: 'file.not_found'
        );
    }

    try {
        $config = require $configPath;
    } catch (\Throwable $e) {
        throw new ConfigurationException(
            "Failed to load configuration: {$e->getMessage()}",
            context: 'file.load_error',
            previous: $e
        );
    }

    return ConfigurationValidator::validate($config);
}

private function handleConfigurationError(ConfigurationException $e): void
{
    $env = (string) (getenv('APP_ENV') ?: 'development');

    if ($env === 'production') {
        // Fail hard in production
        throw new \AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootFailureException(
            "Cannot boot: {$e->getMessage()}",
            previous: $e
        );
    }

    // Log warning in development but allow boot with minimal config
    error_log("Configuration warning: {$e->getMessage()}");
}
```

---

## Priority 2: Deployment Locks (3 hours)

### Step 1: Create Migration
```php
// database/migrations/2026_06_03_000001_create_deployment_locks_table.php
<?php declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;

return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('deployment_locks', static function ($t) {
            $t->id();
            $t->string('lock_key')->unique()->index();
            $t->string('holder', 255)->nullable();  // hostname or process ID
            $t->timestamp('acquired_at')->useCurrent();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('deployment_locks');
    }
};
```

### Step 2: Create DeploymentLockManager
```php
// src/Kernel/Commands/Deployment/DeploymentLockManager.php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Deployment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

final class DeploymentLockManager
{
    private const LOCK_TIMEOUT_SECONDS = 300;
    private const LOCK_KEY = 'migration_deployment';

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function acquireLock(): void
    {
        $holder = $this->getHolderIdentity();
        $expiresAt = $this->getExpirationTime();

        try {
            $this->db->execute(
                'INSERT INTO deployment_locks (lock_key, holder, expires_at) VALUES (?, ?, ?)',
                [self::LOCK_KEY, $holder, $expiresAt]
            );
        } catch (\Exception $e) {
            throw new DeploymentLockedException(
                'Another deployment is already in progress. Try again in a few minutes.'
            );
        }
    }

    public function releaseLock(): void
    {
        $this->db->execute(
            'DELETE FROM deployment_locks WHERE lock_key = ?',
            [self::LOCK_KEY]
        );
    }

    public function isLocked(): bool
    {
        $result = $this->db->queryOne(
            'SELECT 1 FROM deployment_locks WHERE lock_key = ? AND expires_at > NOW()',
            [self::LOCK_KEY]
        );

        return (bool) $result;
    }

    private function getHolderIdentity(): string
    {
        return gethostname() . ':' . getmypid();
    }

    private function getExpirationTime(): string
    {
        $timestamp = time() + self::LOCK_TIMEOUT_SECONDS;
        return date('Y-m-d H:i:s', $timestamp);
    }
}

// Create exception
// src/Kernel/Commands/Deployment/DeploymentLockedException.php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Deployment;

final class DeploymentLockedException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
```

### Step 3: Update MigrateRunCommand to use lock
```php
// In src/Commands/Migrate/MigrateRunCommand.php handle() method
// Add at the beginning:

$lockManager = new DeploymentLockManager($this->service()->driver());

if ($lockManager->isLocked()) {
    $this->alertError(
        'Deployment in progress',
        ['Another deployment is currently running. Please wait.']
    );
    return self::FAILURE;
}

try {
    $lockManager->acquireLock();
    
    // ... existing migration logic ...
    
} finally {
    $lockManager->releaseLock();
}
```

---

## Priority 3: Logging (2 hours)

### Step 1: Create Command Logger
```php
// src/Kernel/Commands/Logging/CommandLogger.php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Logging;

use Psr\Log\LoggerInterface;

final class CommandLogger
{
    private float $startTime;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->startTime = microtime(true);
    }

    public function logStart(string $command, array $argv): void
    {
        $this->logger->info('CLI command started', [
            'command' => $command,
            'argv' => $this->sanitizeArgv($argv),
            'user' => get_current_user(),
            'pid' => getmypid(),
            'timestamp' => date('c'),
        ]);
    }

    public function logEnd(int $exitCode, ?string $error = null): void
    {
        $duration = microtime(true) - $this->startTime;
        $level = $exitCode === 0 ? 'info' : 'warning';

        $this->logger->$level('CLI command completed', [
            'exit_code' => $exitCode,
            'duration_ms' => (int) ($duration * 1000),
            'error' => $error,
        ]);
    }

    private function sanitizeArgv(array $argv): array
    {
        return array_map(function ($arg) {
            if (str_contains((string) $arg, ['password', 'secret', 'token'])) {
                return '***REDACTED***';
            }
            return $arg;
        }, $argv);
    }
}
```

### Step 2: Register Logger in CliPipeline
```php
// In app/bootstrap/base.php, add to Kernel configuration:

use Psr\Log\NullLogger;  // or your actual logger

return Kernel::configure()
    // ... existing configuration ...
    ->withLogging(
        logger: new NullLogger(),  // Replace with real logger
        level: (string) (getenv('LOG_LEVEL') ?: 'info'),
    );
```

---

## Priority 4: Environment Configs (2 hours)

### Step 1: Create Environment-Specific Configs
```
config/
├── let-migrate.php                    (shared defaults)
└── environments/
    ├── local.php                      (dev: memory db)
    ├── testing.php                    (tests: isolated sqlite)
    ├── staging.php                    (staging: real db)
    └── production.php                 (prod: safeguards)
```

### Step 2: local.php
```php
// config/environments/local.php
<?php declare(strict_types=1);

return [
    'connections' => [
        'default' => [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'username' => '',
            'password' => '',
        ],
    ],
    'paths' => [dirname(__DIR__) . '/migrations'],
    'pretend' => false,
    'transactional' => false,  // faster for dev
    'tracking_table' => 'let_migrations',
];
```

### Step 3: production.php
```php
// config/environments/production.php
<?php declare(strict_types=1);

$env = fn(string $key, ?string $default = null) => getenv($key) ?: $default;

return [
    'connections' => [
        'default' => [
            'driver'   => $env('DB_DRIVER', 'mysql'),
            'host'     => $env('DB_HOST'),
            'port'     => (int) $env('DB_PORT', '3306'),
            'database' => $env('DB_NAME'),
            'username' => $env('DB_USER'),
            'password' => $env('DB_PASSWORD'),
        ],
    ],
    'paths' => [dirname(__DIR__) . '/migrations'],
    'pretend' => true,  // ALWAYS preview first in production
    'transactional' => true,
    'tracking_table' => 'let_migrations',
    
    // Production-only safeguards
    'require_lock' => true,
    'require_backup' => true,
    'require_approval' => true,
];
```

### Step 4: Environment Loader
```php
// src/Kernel/Commands/Configuration/EnvironmentLoader.php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Commands\Configuration;

final class EnvironmentLoader
{
    public static function load(string $basePath): array
    {
        $env = (string) (getenv('APP_ENV') ?: 'local');
        $configPath = "{$basePath}/config/environments/{$env}.php";

        if (!is_file($configPath)) {
            throw new ConfigurationException(
                "Configuration not found for environment: {$env}",
                context: "environment.{$env}"
            );
        }

        return require $configPath;
    }
}
```

### Step 5: Update SystemCommandsProvider
```php
// In SystemCommandsProvider::boot()
$config = EnvironmentLoader::load($this->basePath);
$config = ConfigurationValidator::validate($config);

$migrationFactory = MigrateFactory::fromConfig($config);
```

---

## Deployment Checklist

Before deploying to production, verify:

- [ ] Configuration validation passes for all environments
- [ ] Database migration lock table created and tested
- [ ] Deployment lock acquired before migration starts
- [ ] Command execution logged to central logging system
- [ ] Environment-specific configs all exist and validated
- [ ] Production config has `'pretend' => true`
- [ ] Production config requires approval for destructive operations
- [ ] Database backups enabled before migrations run
- [ ] Team has runbooks for failed deployments
- [ ] Rollback procedure tested and documented

---

## Testing Before Production

```bash
# Test configuration validation
php cli migrate:status --config=config/environments/local.php

# Test with environment variable
APP_ENV=production php cli migrate:status

# Test lock mechanism
# Terminal 1:
php cli migrate:run --config=projects/admin/config/let-migrate.php &

# Terminal 2 (while first still running):
php cli migrate:status --config=projects/admin/config/let-migrate.php
# Should fail with lock error

# Test pre-flight validation
php cli migrate:run --config=projects/admin/config/let-migrate.php --pretend

# Test with bad config (should fail clearly)
php cli migrate:status --config=/nonexistent/config.php
```

---

## Summary

| Task | Time | Files | Impact |
|------|------|-------|--------|
| Configuration Validation | 2h | 2 files | Prevents boot-time errors |
| Deployment Locks | 3h | 3 files + 1 migration | Prevents concurrent deployments |
| Logging | 2h | 1 file | Complete audit trail |
| Environment Configs | 2h | 4 files | Environment-specific safety |
| **Total** | **9h** | **11 files** | **Production-ready** |

After implementing these, your system will be ready for enterprise deployments.
