# Commands Visual Reference

## Complete System Architecture

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                            CLI APPLICATION                                   │
│                   (CLIApplication from php-io-cli)                           │
└──────────────────────────────────────────────────────────────────────────────┘
                                      │
                    ┌─────────────────┼─────────────────┐
                    │                 │                 │
       ┌────────────▼────────┐   ┌────▼──────────┐   ┌─▼──────────────┐
       │   Module Commands   │   │ Migrate Cmds  │   │ Seeder Cmds    │
       │                     │   │               │   │                │
       │ ModuleAddCommand    │   │ CliCmdFactory │   │ SeedCmdFactory │
       │ ModuleRemoveCommand │   │      │        │   │       │        │
       └─────────────────────┘   │   all() (25+) │   │  all()         │
                                 │               │   │                │
                                 └───────────────┘   └────────────────┘
```

---

## Module Commands Flow

```
User Input
    │
    ├─ module:add <name> <url> <org>
    │   │
    │   ├─ 1. ValidateConfigStage     → show plan table
    │   ├─ 2. ConfirmStage             → Confirm + manual type entry
    │   ├─ 3. GitSubmoduleAdd
    │   ├─ 4. ScaffoldStage            → mkdir, composer.json
    │   ├─ 5. PatchRootComposer        → repositories[] + require
    │   ├─ 6. ComposerUpdate
    │   │
    │   └─ Output: AlertSuccess(name, details)
    │
    └─ module:remove <name>
        │
        ├─ 1. ShowDestructionManifest  → destruction plan table
        ├─ 2. ConfirmStage             → Confirm
        ├─ 3. TypeConfirmStage         → TextInput("Type 'name' to confirm")
        ├─ 4. GitSubmoduleDeinit
        ├─ 5. CleanCache               → rm -rf .git/modules/
        ├─ 6. CleanGitmodules          → regex cleanup
        ├─ 7. UnpatchRootComposer
        │
        └─ Output: AlertSuccess("Module removed", details)
```

**Progress Bar Pattern:**
```
                    ┌─ ProgressBar::start()
                    │
        Shell::run( │
            cmd,    │ ┌─ tick callback (50ms polls)
            tick: ──┼─┤   $bar->advance(0)  ← redraw only
        )           │ └─ no increment
                    │
                    └─ $bar->advance()  ← increment + redraw
                       (after step completes)
```

---

## Migration Commands Class Hierarchy

```
                    AbstractCommand (php-io-cli)
                           │
                    ┌──────┴──────┐
                    │             │
          LetMigrateCommand   TenantCommand
                │                 │
     ┌──────────┼──────────┐      │
     │          │          │      │
     ├──────────┴────────┬─┴──┬───┴────────┬──────────┬────────────┐
     │                   │    │            │          │            │
MigrateRun         MigrateStatus   Make*      Tenant*  Seed*      Maintenance*
MigrateRollback    MigratePending   Commands   Commands Commands   Commands
MigrateReset       MigrateInstall              
MigrateRefresh     MigrateTo
MigrateFresh       MigrateRedo
                   Generate*
                   Diff*
                   Check*
                   Lint*
```

---

## Factory-Based Command Registration

### CliCommandFactory Pattern

```php
CliCommandFactory::fromConfig($config)
│
├─ migrate()
│  └─ MigrateRunCommand
│  └─ MigrateRollbackCommand
│  └─ MigrateResetCommand
│  └─ [... 7 more ...]
│
├─ generate()
│  └─ MigrateGenerateCommand
│  └─ MigrateDiffCommand
│  └─ MigrateCheckCommand
│
├─ tenant()
│  └─ TenantMigrateRunCommand
│  └─ [... 4 more ...]
│
├─ seed()
│  └─ DbSeedCommand
│
├─ make()
│  └─ MakeMigrationCommand
│  └─ MakeSeederCommand
│  └─ MakeFactoryCommand
│
└─ maintenance()
   └─ MigrateLintCommand
   └─ MigrateSquashCommand
   └─ MigrateBreakpointCommand

    └─ all() → concatenates all the above (25+ commands)
```

---

## Configuration Injection Flow

```
┌───────────────────────────────┐
│   let-migrate.config.php      │
│  (database credentials, etc)  │
└───────────┬───────────────────┘
            │
            ├─ Load (one time)
            │
            ▼
┌───────────────────────────────┐
│  CliCommandFactory::fromConfig │
│  ($config)                     │
└───────────┬───────────────────┘
            │
            ├─ Store $config as instance variable
            │
            ├─ new MigrateRunCommand($config)
            ├─ new MigrateStatusCommand($config)
            ├─ [... all 25+ commands get $config ...]
            │
            ▼
┌───────────────────────────────┐
│  Each LetMigrateCommand        │
│  __construct(?array $config)   │
│  {                             │
│    parent::__construct($config)│  ← stores internally
│  }                             │
└───────────┬───────────────────┘
            │
            ├─ $this->config()    ← access stored config
            ├─ $this->service()   ← builds service from config
            └─ $this->loadConfig()← handles --config override
```

**No --config required!**
```
Before:  php cli migrate:run --config=/path/to/config.php
After:   php cli migrate:run   (config pre-injected)
         php cli migrate:run --config=/other/config.php  (still works as override)
```

---

## Event-Driven Progress in Migrate:run

```
User: php cli migrate:run

    ↓

MigrateRunCommand::handle()
    │
    ├─ 1. service()->pending()
    │     └─ Returns: {migration1, migration2, ...}
    │
    ├─ 2. Setup progress bar
    │     ProgressBar('Running migrations', count($pending))
    │     │
    │     └─ $bar->start()
    │
    ├─ 3. Configure event listeners
    │     $events = $this->events()
    │     │
    │     └─ $events->on(MigrationStarted::class, 
    │          fn(e) => $bar->advance(0, "Running: {e.migration}")
    │       )
    │     └─ $events->on(MigrationFinished::class,
    │          fn(e) => $bar->advance(1, "✓ {e.migration}")
    │       )
    │     └─ $events->on(MigrationFailed::class,
    │          fn(e) => $bar->finish('Failed'); error(...)
    │       )
    │
    ├─ 4. Execute service
    │     service()->run()
    │         │
    │         ├─ For each migration:
    │         │   ├─ dispatch(MigrationStarted)    ← listener #1 fires
    │         │   ├─ execute SQL
    │         │   ├─ dispatch(MigrationFinished)   ← listener #2 fires
    │         │   │                                  (bar.advance(1) runs here)
    │         │   └─ or dispatch(MigrationFailed)  ← listener #3 fires
    │         │                                     (bar.finish() runs here)
    │         └─ return MigrationResult
    │
    ├─ 5. Finish progress bar
    │     $bar->finish('All migrations applied')
    │
    └─ 6. Output result summary
        alertSuccess("Migrations applied", [
            "Count: {result.appliedCount()}",
            "Batch: {result.batch}",
        ])

Output:
┌──────────────────────────────┐
│ Running migrations    ████░░░ │  ← updates live via events
│ ✓ 2024_01_01_000001_create   │
│ ✓ 2024_01_02_000001_add_col  │
│ ✓ 2024_01_03_000001_alter    │
└──────────────────────────────┘
✔ Migrations applied
  Count: 3
  Batch: 5
```

---

## Seeder Commands Flow (Mirrors Migration Pattern)

```
SeedCommandFactory::fromRunner($runner)
│
├─ run()     → SeedRunCommand     (execute all pending seeders)
└─ status()  → SeedStatusCommand  (show seeder statuses)


SeedRunCommand::handle()
    │
    ├─ 1. runner()->status()         → get seeder statuses
    │
    ├─ 2. Show discovery spinner
    │     $spinner = $this->spinner('Discovering seeders')
    │     $spinner->start()
    │     [... async work ...]
    │     $spinner->stop(message)
    │
    ├─ 3. Show status table
    │     Table(['Status', 'Seeder', 'Batch'])
    │     ├─ ✔ run     UserSeeder    1
    │     └─ ⟳ pending PostSeeder    —
    │
    ├─ 4. Run seeders
    │     runner()->run(force: false)
    │     └─ executes with progress spinner
    │
    └─ 5. Summary
        alertSuccess("Seeders executed", [...])
```

---

## Options Available on Every Command

### Automatic Options (from registerCommonOptions)

```
migrate:run [OPTIONS]

  --config=PATH           Override config file path
                         Default: uses constructor-injected config
                         
  --connection=NAME      Select named database (multi-connection configs)
                         Default: 'default' from config
                         
  --json                 Output machine-readable JSON instead of human format
                         (not available on all commands)
```

### Command-Specific Options

```
migrate:run
  --pretend              Show SQL without executing
  --force                Skip destructive operation guard
  --lock                 Hold advisory deploy lock
  --lock-timeout=SECS    Wait time for lock (default: 10)

make:migration
  --create=TABLE         Create TABLE stub
  --table=TABLE          Alter TABLE stub
  --path=DIR             Custom output directory

migrate:status
  --pending              Show only pending
  --applied              Show only applied
  --format=json|table    Output format (default: table)
```

---

## Error Handling & Exit Codes

```
Command Execution
    │
    ├─ Validation ✓
    │   └─ Config load ✓
    │       └─ Service build ✓
    │           └─ Main logic
    │               │
    │               ├─ Success
    │               │   └─ alertSuccess(...)
    │               │   └─ exit(0)
    │               │
    │               ├─ Warning (non-fatal)
    │               │   └─ warning(...)
    │               │   └─ exit(0)
    │               │
    │               └─ Failure
    │                   └─ alertError(...) or error(...)
    │                   └─ exit(1)
    │
    └─ Exception ✗
        └─ try/catch at command level
            └─ alertError(message, context)
            └─ exit(1)
```

---

## File Organization

```
src/Commands/
│
├─ ModuleAddCommand.php          (367 lines) ← git submodule add
├─ ModuleRemoveCommand.php       (340 lines) ← git submodule remove
│
├─ Migrate-old/                  ← DEPRECATED (legacy patterns)
│   ├─ AbstractMigrateCommand.php
│   ├─ MigrationCommandFactory.php
│   └─ [14 command classes]
│
├─ Migrate/                       ← ACTIVE (25+ commands)
│   ├─ LetMigrateCommand.php     (base class, ~250 lines)
│   ├─ CliCommandFactory.php     (factory, ~180 lines)
│   ├─ TenantCommand.php         (multi-tenant base)
│   │
│   ├─ MigrateRunCommand.php     (core: apply pending)
│   ├─ MigrateRollbackCommand.php(core: undo batch)
│   ├─ MigrateResetCommand.php   (core: reset all)
│   ├─ MigrateRefreshCommand.php (core: reset+run)
│   ├─ MigrateFreshCommand.php   (core: drop all+run)
│   ├─ MigrateStatusCommand.php  (core: show status)
│   ├─ MigratePendingCommand.php (core: pending count)
│   ├─ MigrateInstallCommand.php (core: init tables)
│   ├─ MigrateToCommand.php      (core: migrate to specific)
│   ├─ MigrateRedoCommand.php    (core: redo last)
│   │
│   ├─ MigrateGenerateCommand.php(introspect: auto-generate)
│   ├─ MigrateDiffCommand.php    (introspect: diff schema)
│   ├─ MigrateCheckCommand.php   (introspect: verify)
│   ├─ MigrateLintCommand.php    (introspect: lint SQL)
│   │
│   ├─ MakeMigrationCommand.php  (make: scaffold migration)
│   ├─ MakeSeederCommand.php     (make: scaffold seeder)
│   ├─ MakeFactoryCommand.php    (make: scaffold factory)
│   │
│   ├─ TenantMigrateRunCommand.php        (multi-tenant: apply to all)
│   ├─ TenantMigrateRollbackCommand.php   (multi-tenant: undo on all)
│   ├─ TenantMigrateResetCommand.php      (multi-tenant: reset on all)
│   ├─ TenantMigrateRefreshCommand.php    (multi-tenant: refresh on all)
│   ├─ TenantMigrateStatusCommand.php     (multi-tenant: status for all)
│   │
│   ├─ MigrateSquashCommand.php  (maintenance: combine migrations)
│   ├─ MigrateBreakpointCommand.php(maintenance: set breakpoint)
│   └─ DbSeedCommand.php         (execute seeders)
│
└─ Seed/                          ← Seeder commands
    ├─ AbstractSeedCommand.php   (base class)
    ├─ SeedCommandFactory.php    (factory)
    ├─ SeedRunCommand.php        (run seeders)
    └─ SeedStatusCommand.php     (show status)
```

---

## Lines of Code by Component

| Component | LOC | Purpose |
|-----------|-----|---------|
| ModuleAddCommand | 367 | Git submodule management |
| ModuleRemoveCommand | 340 | Git submodule removal |
| LetMigrateCommand | 250 | Base class for all migration commands |
| CliCommandFactory | 180 | Factory for 25+ migration commands |
| All Migrate/* commands | ~3000 | Database migration & introspection |
| All Seed/* commands | ~800 | Database seeding |
| **Total** | **~4600** | Production-ready CLI system |

---

## Key Design Decisions ✓

| Decision | Why | Trade-off |
|----------|-----|-----------|
| Factory Pattern | Single point of command construction | Slight indirection in bootstrap |
| Constructor Injection | Config loaded once, shared to all | Requires parent::__construct() in subclasses |
| Lazy Service | Service built on first access | One service per command invocation |
| Event Hooks | Extensibility without modifying base | Need to understand event lifecycle |
| No Kernel Coupling | CLI is independent, runs before boot | Separate code paths from HTTP layer |
| Multiple Progress Bars | Complex shell operations | Must manually coordinate bar updates with ticks |

---

## Summary

Your command architecture is:
- ✅ **Well-organized** (3 domains, clear responsibilities)
- ✅ **Consistent** (factory pattern, inheritance hierarchy)
- ✅ **Extensible** (easy to add new commands, event hooks available)
- ✅ **Production-ready** (error handling, exit codes, user feedback)
- ✅ **Framework-aligned** (respects GDA principles, no global state)
- ✅ **Documented** (this guide + command docblocks)

**Recent improvements (June 2026):**
- Fixed MigrateMakeCommand base class inheritance
- Removed duplicate MigrateMakeCommand (was not used)
- All 25+ migration commands now consistent and injectable
