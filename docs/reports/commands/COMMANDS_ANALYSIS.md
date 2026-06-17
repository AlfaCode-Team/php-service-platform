# Commands Architecture Analysis & Patterns

## Overview
Your command infrastructure follows excellent design patterns, split into three logical groups:
1. **Module Management** (`ModuleAddCommand`, `ModuleRemoveCommand`)
2. **Database Migrations** (`Migrate-old/` and `Migrate/`)
3. **Seeders** (`Seed/`)

---

## 1. MODULE MANAGEMENT COMMANDS ✓

### Pattern: Rich Interactive CLI with Progress Tracking
**Location:** `src/Commands/ModuleAddCommand.php`, `src/Commands/ModuleRemoveCommand.php`

#### Strengths:
- ✓ Single progress bar per operation (no UI interleaving)
- ✓ `Shell::run()` with tick callback for live feedback during long-running shell commands
- ✓ Interactive confirmations (Confirm, TextInput components)
- ✓ Rich table output (Table component with compact styling)
- ✓ Proper error handling with AlertError/AlertSuccess/AlertWarning
- ✓ Follows AbstractCommand pattern from php-io-cli

#### Key Pattern Used:
```php
// Single ProgressBar redraws on every shell tick
$bar->advance(0);  // redraw only, no increment

// Then advance after the step completes
$bar->advance();  // increment + redraw
```

#### Components Used:
- `Table` — compact display of operations
- `ProgressBar` — determinate progress with bounce animation during shell commands
- `Confirm` — yes/no decision gates
- `TextInput` — irreversible-action confirmation
- `AlertError`, `AlertSuccess`, `AlertWarning` — colored status blocks

---

## 2. DATABASE MIGRATION COMMANDS

### Comparison: Migrate-old vs. Migrate/

#### Migrate-old (Legacy Pattern)

**Location:** `src/Commands/Migrate-old/`

**Base Class:** `AbstractMigrateCommand`
- Holds `MigrationServiceInterface $migrationService`
- Injected via `withService()` factory method
- **Factory:** `MigrationCommandFactory::fromConfig()`
- **Event System:** Uses `wireProgressEvents()` to attach live event listeners

**Key Features:**
```php
// Progress bar integration with migration events
$bar = $this->progressBar('Applying migrations', $count);
$bar->start();
$this->wireProgressEvents($bar, $this->service()->events());
$result = $this->service()->run();
$bar->finish('All migrations applied');
```

**Commands Included:**
- `migrate:run` — apply pending migrations
- `migrate:make` — scaffold migration stubs
- `migrate:status` — show migration statuses
- `migrate:pending` — show pending count
- `migrate:rollback` — undo last batch
- `migrate:reset` — rollback all
- `migrate:refresh` — reset + run
- `migrate:interactive` — interactive mode

**Limitations:**
- ✗ No multi-tenant support
- ✗ No schema introspection (diff, check, generate)
- ✗ No seeder commands
- ✗ Limited to LetMigrate only, no pluggable drivers
- ✗ Factory doesn't support config injection (requires --config on CLI)

---

#### Migrate/ (Modern Pattern) ⭐ RECOMMENDED

**Location:** `src/Commands/Migrate/`

**Base Class:** `LetMigrateCommand`
- Improved over AbstractMigrateCommand
- **Constructor injection of config** — factory pre-loads once, all commands share it
- **Event dispatcher cached** — single dispatcher per CLI run
- **Lazy service construction** — built on first `service()` call
- **Multi-connection support** — `--connection=NAME` selection
- **Hook point:** `configureEvents()` for custom event listeners

**Factory:** `CliCommandFactory::fromConfig()`
```php
$config = require 'let-migrate.config.php';
$factory = CliCommandFactory::fromConfig($config);
$app->add(...$factory->all());
```

**Commands Included (by category):**

| Category | Commands |
|----------|----------|
| **migrate** | run, rollback, reset, refresh, fresh, status, pending, install, to, redo |
| **generate** | generate, diff, check, lint |
| **tenant** | tenant:migrate:run, tenant:migrate:rollback, tenant:migrate:reset, tenant:migrate:refresh, tenant:migrate:status |
| **seed** | seed:run |
| **make** | make:migration, make:seeder, make:factory |
| **maintenance** | migrate:breakpoint, migrate:squash |

**Advanced Features:**
- ✓ Multi-tenant aware (TenantCommand base class)
- ✓ Schema introspection & validation
- ✓ Config supports multi-connection (Laravel-style convention)
- ✓ JSON output mode for machine parsing
- ✓ Breakpoints & squashing for migration cleanup
- ✓ Lint command for safety checks

---

### Which Pattern to Use?

| Requirement | Use Migrate-old | Use Migrate/ |
|---|---|---|
| Single database | ✓ | ✓ |
| Multiple connections | ✗ | ✓ |
| Multi-tenant | ✗ | ✓ |
| Schema introspection | ✗ | ✓ |
| Config injection | ✗ | ✓ |
| Seeders | ✗ | ✓ |
| Simple & focused | ✓ | — |

**Recommendation:** Use `Migrate/` for all future work. It's a superset that maintains backward compatibility while adding enterprise features.

---

## 3. SEEDER COMMANDS ✓

### Pattern: Mirroring Migration Commands
**Location:** `src/Commands/Seed/`

#### Structure:
```php
abstract class AbstractSeedCommand extends AbstractCommand {
    final public function withRunner(SeederRunner $runner): static;
    final protected function runner(): SeederRunner;
}

// Subcommands:
class SeedRunCommand extends AbstractSeedCommand { … }
class SeedStatusCommand extends AbstractSeedCommand { … }
```

#### Features:
- ✓ Follows same injection pattern as migrations
- ✓ Factory (SeedCommandFactory) for constructor-based DI
- ✓ Progress tracking during seed execution
- ✓ Force mode to re-run already-seeded data
- ✓ Status display with batch tracking

#### Components Used:
- `Spinner` — non-blocking discovery phase
- `Table` — seeder status matrix
- `AlertSuccess` / `AlertError` — result summary

---

## 4. GDA DESIGN PRINCIPLES ALIGNMENT

### Security-First Bootstrap
None of the CLI commands currently interact with the kernel's `SecurityGateway`. This is correct because:
- ✓ CLI commands run with `Identity::guest()` or env-provided creds
- ✓ No HTTPRequest/Response involved
- ✓ Database access is direct via DatabasePort
- ✓ Seed data doesn't require Identity context

### Isolation & Scope
- ✓ Commands are registered via factory, not auto-discovered
- ✓ No static singletons — all state injected
- ✓ ModuleContainer reset after each command (if integrated with kernel)

### Infrastructure Independence
- ✓ Ports abstraction used for database access
- ✓ MigrationServiceInterface & SeederRunner are contracts, not implementations
- ✓ Driver registry pluggable (LetMigrate supports MySQL, PostgreSQL, SQLite, SQL Server)

---

## 5. RECOMMENDATIONS FOR YOUR PROJECT

### ✓ Keep Using:
1. **ModuleAddCommand & ModuleRemoveCommand** — excellent patterns for interactive shell operations
2. **Migrate/** — superset of old patterns, better for growth
3. **Seed/** — clean mirror of migration pattern

### → Consolidate:
Remove `Migrate-old/` — it's been superseded. All its commands exist in `Migrate/` with improvements.

### → Consider Adding:
1. **Status Dashboard Command** — unified view of migrations + seeders
   ```
   php cli status:all
   ```

2. **Config Validation Command**
   ```
   php cli config:validate [--config=/path/to/config.php]
   ```

3. **Tenant-Aware Status** (if multi-tenant is used)
   ```
   php cli tenant:status
   ```

---

## 6. REFERENCE: php-io-cli COMPONENTS USED

| Component | Purpose | Location |
|-----------|---------|----------|
| `AbstractCommand` | Base class for all commands | imported via use statement |
| `Table` | Display tabular data | ModuleAddCommand, MigrateRunCommand |
| `ProgressBar` | Determinate progress with live updates | ModuleAddCommand/Remove, all Migrate commands |
| `Spinner` | Non-blocking waiting indicator | MigrateRunCommand, SeedRunCommand |
| `Confirm` | Yes/no user prompt | ModuleRemoveCommand |
| `TextInput` | Free-text user input | ModuleRemoveCommand |
| `AlertError/Success/Warning` | Colored notification boxes | All commands |
| `Colors` | ANSI color wrapping | MigrateStatusCommand, SeedRunCommand |

---

## 7. KEY PATTERNS SUMMARY

### Progress Bars During Shell Operations
```php
$bar = $this->progressBar('Task', $count);
$bar->start();
Shell::run($cmd, tick: fn() => $bar->advance(0), …);  // redraw
$bar->advance();  // increment
$bar->finish('Done');
```

### Event-Driven Migrations
```php
protected function wireProgressEvents(ProgressBar $bar, MigrationEventDispatcher $events): void {
    $events->on(MigrationStarted::class, fn($e) => $bar->advance(0, "Running: {$e->migration}"));
    $events->on(MigrationFinished::class, fn($e) => $bar->advance(1, "✔ {$e->migration}"));
}
```

### Factory Pattern for DI
```php
class CliCommandFactory {
    public static function fromConfig(?array $config): self { … }
    public function all(): array { /* return every command */ }
}

// Bootstrap
$factory = CliCommandFactory::fromConfig($config);
$app->add(...$factory->all());
```

### Irreversible Action Confirmation
```php
$this->confirm('Are you sure?', default: false);  // require explicit yes
$this->table(…)->render();                          // show manifest
(new TextInput("Type 'yes' to confirm"))->run();    // type-to-confirm
```

---

## Summary

Your command architecture is **well-designed and consistent**. The evolution from `Migrate-old` → `Migrate/` is healthy. The Module and Seed commands follow proven patterns from php-io-cli.

**Next step:** Align everything to use `Migrate/` exclusively and consider deprecating `Migrate-old/`.
