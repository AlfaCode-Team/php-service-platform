# Commands Framework Update Plan

## Issues Found

### 1. ❌ Inconsistent Base Class in Migrate/
- **Problem:** `MigrateMakeCommand.php` extends `AbstractMigrateCommand` but this class doesn't exist in the `Migrate/` namespace
- **Root Cause:** Copy-paste from `Migrate-old/` without updating the extends clause
- **Solution:** Change to extend `LetMigrateCommand` and implement `registerCommonOptions()` call

### 2. ⚠️ Missing Framework Integration
- **Problem:** Commands don't leverage the full GDA framework potential
- **Location:** Both Migrate-old/ and Migrate/ are standalone
- **Opportunity:** Commands could be registered as Kernel-aware services with dependency injection

### 3. ⚠️ Configuration Injection Incomplete
- **Problem:** `LetMigrateCommand` supports constructor injection but `MigrateMakeCommand` doesn't call parent constructor
- **Solution:** Ensure all migration commands properly call `parent::__construct()` with config parameter

---

## Fixes Required

### Priority 1: Fix MigrateMakeCommand (CRITICAL)

**Current (Broken):**
```php
final class MigrateMakeCommand extends AbstractMigrateCommand
{
    protected function configure(): void
    {
        // missing registerCommonOptions()
    }
}
```

**Fixed:**
```php
final class MigrateMakeCommand extends LetMigrateCommand
{
    public function __construct(?array $config = null)
    {
        parent::__construct($config);
    }

    protected function configure(): void
    {
        $this->name = 'make:migration';
        $this->description = 'Scaffold a new migration file';
        
        $this->registerCommonOptions(withJson: false);  // migrations files don't benefit from --json
        $this->addArgument('name', 'Descriptive snake_case migration name');
        $this->addOption('create', 'c', 'Table name for CREATE TABLE stub', acceptsValue: true);
        $this->addOption('table', 't', 'Table name for ALTER TABLE stub', acceptsValue: true);
        $this->addOption('path', 'p', 'Custom output directory', acceptsValue: true);
    }

    protected function handle(): int
    {
        // ... uses $this->service() which is now properly injected
    }
}
```

### Priority 2: Align All Migrate/ Commands

The following commands in `Migrate/` need the same fix:
- `MakeMigrationCommand.php` — ✓ Already correct
- `MakeSeederCommand.php` — Check if extends LetMigrateCommand
- `MakeFactoryCommand.php` — Check if extends LetMigrateCommand
- Any other make:* commands

### Priority 3: Add Framework Integration Points

Create a kernel-aware variant (optional, for future):
```php
// src/Kernel/Commands/MigrationBootProvider.php
// Could register migration commands into the kernel's CliPipeline
// This would allow injection of DatabasePort, etc.
```

---

## What the Fixes Achieve

| Current | After Fix |
|---------|-----------|
| MigrateMakeCommand has undefined parent class | ✓ Extends LetMigrateCommand correctly |
| No config injection in make:* commands | ✓ Constructor injects config like all other commands |
| Inconsistent with Migrate/LetMigrateCommand pattern | ✓ All commands inherit from LetMigrateCommand |
| --json, --connection, --config not available | ✓ registerCommonOptions() adds them automatically |
| Cannot use --json for piping output | ✓ Supported where applicable |

---

## Backward Compatibility

**None required.** These are internal CLI commands:
- ✓ Safe to change any class method signatures
- ✓ CLI arg/option changes should maintain backward compatibility (done in fixes above)
- ✓ No public API affected
- ✓ Users won't notice these changes except reliability improvements

---

## Testing Checklist

After fixes:

```bash
# Test make commands
php cli make:migration create_users_table
php cli make:migration add_email_to_users --table=users
php cli make:seeder UserSeeder
php cli make:factory UserFactory

# Test with config override
php cli make:migration --config=/path/to/config.php test_table

# Test JSON output (where supported)
php cli migrate:status --json | jq '.

# Test multi-connection
php cli migrate:run --connection=secondary

# Test pretend/dry-run
php cli migrate:run --pretend
```

---

## GDA Alignment Checklist

- ✓ Commands are AbstractCommand subclasses (not static helpers)
- ✓ No global singletons (all injected)
- ✓ Ports abstraction respected (database via LetMigrate, not direct PDO)
- ✓ Configuration externalized (loaded once, injected to all commands)
- ✓ Error handling follows framework patterns (AlertError, AlertSuccess, error())
- ✓ CLI components from php-io-cli (Progress, Table, Spinner, etc.)
- ✓ Security: CLI commands run as guest Identity (correct for maintenance tasks)
- ✓ No request/response objects leaked into CLI context

---

## Summary

**Files to Update:**
1. `src/Commands/Migrate/MigrateMakeCommand.php` — Fix extends + constructor
2. `src/Commands/Migrate/MakeSeederCommand.php` — Verify base class
3. `src/Commands/Migrate/MakeFactoryCommand.php` — Verify base class
4. Any other make:* commands in Migrate/

**Estimated Effort:** 10 minutes (mostly find-replace on extends clause)

**Impact:** All commands will be consistent, properly injectable, and aligned with GDA principles.
