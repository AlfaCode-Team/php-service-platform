# Command Architecture Guide

Complete reference for your AlfacodeTeam PhpServicePlatform CLI commands.

---

## Quick Overview

Your command system is organized into **3 domains**, each with its own pattern:

```
┌─────────────────────────────────────────────────────────────────┐
│                     CLI APPLICATION LAYER                       │
├────────────────────┬──────────────────────┬────────────────────┤
│  Module Mgmt       │   Database Migrations │  Database Seeders  │
│  (Git Submodules)  │   (LetMigrate)       │  (LetMigrate)      │
├────────────────────┼──────────────────────┼────────────────────┤
│ ModuleAddCommand   │ MigrateRunCommand    │ SeedRunCommand     │
│ ModuleRemoveCommand│ MigrateStatusCommand │ SeedStatusCommand  │
│                    │ MakeMigrationCommand │                    │
│                    │ [20+ more commands]  │                    │
│                    │ TenantCommands       │                    │
└────────────────────┴──────────────────────┴────────────────────┘
```

---

## 1. MODULE MANAGEMENT COMMANDS

**Location:** `src/Commands/`

### Pattern: Interactive Shell Operations

These commands manage git submodules with rich terminal UI.

#### Commands

| Command | Purpose | Usage |
|---------|---------|-------|
| `module:add` | Add a git submodule + Composer registration | `php cli module:add auth git@github.com:acme/auth.git acme` |
| `module:remove` | Remove a submodule + clean up traces | `php cli module:remove auth` |

#### Key Features

- **Single Progress Bar** — shell operations redraw the same bar (no multiplexing)
- **Interactive Confirmations** — Confirm + TextInput for destructive ops
- **Rich Tables** — compact display of what will change
- **Proper Teardown** — cascading deletion with backups

#### Technical Pattern

```php
// 1. Create progress bar
$bar = $this->progressBar('Task name', total: 6);
$bar->start();

// 2. Run shell command with live tick feedback
$result = Shell::run(
    $command,
    tick: fn() => $bar->advance(0),  // redraw, no increment
    cwd: $projectRoot,
);

// 3. Advance after step completes
if (!$result->failed()) {
    $bar->advance();  // increment + redraw
}

// 4. Finish with summary
$bar->finish('All done');
$this->alertSuccess('Success message', details);
```

**File Patterns:**
```
ModuleAddCommand.php
├── configure()       — define args + options
├── handle()          — main logic: plan → confirm → execute
├── helpers           — runStep(), moduleExists(), patchRootComposer()
└── Lexical scope     — no instance state, no statics
```

---

## 2. DATABASE MIGRATION COMMANDS

**Location:** `src/Commands/Migrate/`

### Pattern: Enterprise Database Versioning (LetMigrate)

25+ commands for database schema management, multi-tenant deployments, and introspection.

#### Architecture

```
CliCommandFactory::fromConfig($config)
    ├─ all()           → every command, 25+
    ├─ migrate()       → run, rollback, reset, refresh, fresh, status, etc.
    ├─ generate()      → schema introspection (generate, diff, check, lint)
    ├─ tenant()        → multi-tenant variants (tenant:migrate:run, etc.)
    ├─ seed()          → database seeders
    ├─ make()          → stub generators (make:migration, make:seeder, make:factory)
    └─ maintenance()   → breakpoints, squashing
```

#### Base Class: `LetMigrateCommand`

```php
abstract class LetMigrateCommand extends AbstractCommand
{
    // Constructor injection of pre-loaded config
    public function __construct(?array $config = null) { … }

    // Lazy service construction — built on first access, cached
    protected function service(): MigrationServiceInterface { … }

    // Hook for custom event listeners
    protected function configureEvents(MigrationEventDispatcher $events): void { }

    // Common options: --config, --connection, --json
    protected function registerCommonOptions(bool $withJson = true): void { … }

    // Access pre-loaded or CLI config
    protected function config(): array { … }

    // Check if user wants JSON output
    protected function wantsJson(): bool { … }

    // Emit JSON for machine parsing
    protected function emitJson(array $data): void { … }
}
```

#### All Commands (25+)

**Core Migration Commands** (CliCommandFactory::migrate())
```
migrate:run           — apply pending migrations
migrate:rollback      — undo last batch
migrate:reset         — rollback all migrations
migrate:refresh       — reset + run (full cycle)
migrate:fresh         — drop all tables + run (nuclear reset)
migrate:status        — display migration statuses
migrate:pending       — show pending count
migrate:install       — create migrations table
migrate:to <target>   — migrate to specific version
migrate:redo          — redo last migration
```

**Schema Introspection** (CliCommandFactory::generate())
```
migrate:generate      — auto-generate migration from current schema
migrate:diff          — diff current schema vs pending migrations
migrate:check         — verify schema consistency
migrate:lint          — lint SQL for safety issues
```

**Maker Commands** (CliCommandFactory::make())
```
make:migration <name>       — scaffold new migration
make:seeder <name>          — scaffold new seeder
make:factory <table>        — scaffold new data factory
```

**Multi-Tenant** (CliCommandFactory::tenant())
```
tenant:migrate:run          — run migrations on all tenants
tenant:migrate:rollback     — rollback on all tenants
tenant:migrate:reset        — reset on all tenants
tenant:migrate:refresh      — refresh on all tenants
tenant:migrate:status       — show status for all tenants
```

**Maintenance** (CliCommandFactory::maintenance())
```
migrate:breakpoint <name>   — mark a migration as "do not go past this"
migrate:squash              — combine old migrations into a single file
```

**Seeder Command** (CliCommandFactory::seed())
```
seed:run                    — execute all pending seeders
```

#### Options Across All Commands

**Global Options (registerCommonOptions)**
```
--config=<path>       Override config file path
--connection=<name>   Use named database connection (multi-connection configs)
--json               Emit JSON instead of human output
```

**Common Flags**
```
--pretend             Capture SQL without executing (preview mode)
--force               Bypass destructive-operation guard
--lock                Hold advisory deploy lock (prevent concurrent runs)
--lock-timeout=<sec>  Seconds to wait for lock (default: 10)
--dry-run             Alias for --pretend
```

#### Usage Examples

```bash
# Core operations
php cli migrate:run
php cli migrate:run --pretend                    # Preview SQL
php cli migrate:run --force                      # Bypass safety checks
php cli migrate:run --lock --lock-timeout=30     # Deploy-safe locking

# Status & introspection
php cli migrate:status
php cli migrate:pending
php cli migrate:status --pending                 # Show only pending
php cli migrate:status --json | jq .             # Parse with jq

# Multi-connection
php cli migrate:status --connection=secondary
php cli migrate:run --connection=postgres

# Makers
php cli make:migration create_users_table
php cli make:migration add_email_to_users --table=users
php cli make:seeder UsersSeeder
php cli make:factory users --locale=es

# Tenant operations (if configured)
php cli tenant:migrate:run
php cli tenant:migrate:status --json
```

#### Event-Driven Progress

Commands can hook into LetMigrate's event system for live feedback:

```php
class MyMigrationCommand extends LetMigrateCommand
{
    protected function configureEvents(MigrationEventDispatcher $events): void
    {
        $events->on(MigrationStarted::class, fn($e) => /* … */);
        $events->on(MigrationFinished::class, fn($e) => /* … */);
        $events->on(MigrationFailed::class, fn($e) => /* … */);
    }
}
```

MigrateRunCommand uses this for live progress bars with per-migration status.

#### Factory Initialization

```php
// In your bootstrap / CLI entry point:
$config = require __DIR__ . '/let-migrate.config.php';

$factory = CliCommandFactory::fromConfig($config);

// All commands pre-wired with config (no --config required):
$app->add(...$factory->all());
```

Or, for specific command groups:

```php
$factory->migrate()        // 10 core commands
$factory->generate()       // 3 introspection commands
$factory->tenant()         // 5 tenant variants
$factory->seed()           // 1 seeder command
$factory->make()           // 3 stub generators
$factory->maintenance()    // 3 maintenance commands
```

---

## 3. DATABASE SEEDER COMMANDS

**Location:** `src/Commands/Seed/`

### Pattern: Mirroring Migration Commands

Follows identical structure to Migrate/ for consistency.

#### Architecture

```
SeedCommandFactory::fromRunner($runner)
    ├─ all()        → every seeder command
    ├─ run()        → SeedRunCommand
    └─ status()     → SeedStatusCommand
```

#### Base Class: `AbstractSeedCommand`

```php
abstract class AbstractSeedCommand extends AbstractCommand
{
    final public function withRunner(SeederRunner $runner): static;
    final protected function runner(): SeederRunner;
}
```

#### Commands

| Command | Purpose |
|---------|---------|
| `seed:run` | Execute all pending seeders in dependency order |
| `seed:status` | Show seeder statuses |

#### Options

```
--force           Re-run all seeders even if already seeded
--no-progress     Suppress spinner output
```

---

## Component Inventory

All commands use **php-io-cli** components for rich terminal output:

### Progress Indicators

| Component | Use Case | Example |
|-----------|----------|---------|
| `ProgressBar` | Long operations with steps | Migration runs (determinate progress) |
| `Spinner` | Waiting for results | Discovery phase, loading schema |

### User Input

| Component | Use Case | Example |
|-----------|----------|---------|
| `Confirm` | Yes/no question | "Are you sure you want to remove this module?" |
| `TextInput` | Free-text with validation | "Type 'admin' to confirm" |
| `Select` | Single choice from list | Choose a locale (en/es/fr) |

### Output/Display

| Component | Use Case |
|-----------|----------|
| `Table` | Structured data (migrations status, operation plan) |
| `Alert` (Success/Error/Warning) | Colored result boxes |
| `Colors` | ANSI color wrapping for status indicators |

### Configuration Output

| Method | Purpose |
|--------|---------|
| `$this->section($title)` | Heading for a section |
| `$this->info($msg)` | Informational line |
| `$this->warning($msg)` | Yellow warning line |
| `$this->error($msg)` | Red error line |
| `$this->newLine()` | Blank line |
| `$this->muted($msg)` | Gray/muted text |

---

## GDA Framework Integration

### Design Principles ✅

1. **Security First**
   - CLI commands are system maintenance tasks
   - Run with `Identity::guest()` (no authentication required)
   - No SecurityGateway interaction (not needed for DB maintenance)

2. **Infrastructure Independence**
   - Database access via `MigrationServiceInterface` (contract, not impl)
   - Drivers pluggable via `DriverRegistry` (MySQL, PostgreSQL, SQLite, SQL Server)
   - No hardcoded connections or vendor SDK calls in commands

3. **Explicit Over Implicit**
   - All commands registered in factory, never auto-discovered
   - Configuration externalized, injected, never global
   - No magic or convention beyond LetMigrate's own patterns

4. **Isolation by Default**
   - Commands don't hold state (stateless pattern)
   - No static singletons, no cross-command leakage
   - Each invocation is independent

5. **Fail-Fast**
   - All errors caught and translated to user-friendly messages
   - Proper exit codes (0 = success, 1 = failure)
   - Clear error context (file paths, line numbers, suggestions)

### No Kernel Coupling

Commands are **intentionally standalone** because:
- ✓ They run before the HTTP kernel boots
- ✓ They manage infrastructure (schema, seeds) used by the kernel
- ✓ They need zero-dependency execution (CLI scripts, CI/CD)
- ✓ They can be run independently without loading modules

This is **correct design** — CLI operations should not depend on application state.

---

## Quick Reference

### Command Paths

```
Module Management
  src/Commands/ModuleAddCommand.php
  src/Commands/ModuleRemoveCommand.php

Migrations (25+ commands)
  src/Commands/Migrate/
  ├─ LetMigrateCommand.php          (base class)
  ├─ CliCommandFactory.php          (entry point)
  ├─ MigrateRunCommand.php          (apply pending)
  ├─ MigrateStatusCommand.php       (show status)
  ├─ MakeMigrationCommand.php       (scaffold stub)
  ├─ TenantCommand.php              (multi-tenant base)
  └─ [20+ others]

Seeders
  src/Commands/Seed/
  ├─ AbstractSeedCommand.php        (base class)
  ├─ SeedCommandFactory.php         (entry point)
  └─ SeedRunCommand.php             (execute seeders)
```

### Bootstrap Pattern

```php
// app/cli (or your CLI entry point)
$migrationConfig = require 'projects/admin/config/let-migrate.php';
$factory = \AlfacodeTeam\PhpServicePlatform\Commands\Migrate\CliCommandFactory::fromConfig($migrationConfig);

$app = new CLIApplication('MyApp', '1.0');
$app->add(
    new \AlfacodeTeam\PhpServicePlatform\Commands\ModuleAddCommand(),
    new \AlfacodeTeam\PhpServicePlatform\Commands\ModuleRemoveCommand(),
    ...$factory->all(),
);
$app->run();
```

### Exit Codes

```
0   Success (migration applied, command completed)
1   Failure (validation error, migration failed, missing config)
```

---

## Performance Notes

- **Config Injection** — Loaded once in factory, shared to all commands
- **Lazy Service** — MigrationService built on first `$this->service()` call
- **Cached Events** — Single dispatcher per CLI run, shared to all listeners
- **Connection Pooling** — Reused within single command invocation
- **Progress Bars** — Non-blocking animation (time-gated bounces, safe for rapid calls)

---

## Testing Commands

```bash
# List all available commands
php cli list

# Get help for a command
php cli help migrate:run
php cli migrate:run --help

# Run with verbosity
php cli migrate:run -vv      # Very verbose

# Dry-run everything
php cli migrate:run --pretend

# Interactive migrations
php cli migrate:interactive  # Step through each pending migration
```

---

## What's Changed (Recent Fixes)

✅ **Fixed June 3, 2026:**
- `MigrateMakeCommand` — corrected base class from `AbstractMigrateCommand` to `LetMigrateCommand`
- Removed duplicate `MigrateMakeCommand.php` (was not registered in factory)
- All 25+ commands now consistent and properly injectable

---

## Next Steps

1. ✅ Commands are production-ready
2. Run the test checklist above to verify everything works
3. Integrate into your CLI bootstrap script
4. Update any deployment/CI scripts to use new commands
5. Document any custom commands you add following these patterns

---

## Questions?

Refer to:
- `COMMANDS_ANALYSIS.md` — deep dive into design patterns
- `COMMANDS_FIX_SUMMARY.md` — what was fixed and why
- Individual command docblocks for specific usage
