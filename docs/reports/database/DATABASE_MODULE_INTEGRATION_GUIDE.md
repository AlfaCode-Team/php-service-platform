# Database Module Integration Guide

**Status:** ✅ Integrated into framework bootstrap  
**Date:** 2026-06-04  
**Impact:** Replaces old `PdoDatabase` with multi-driver support

---

## What Changed

### Before (Old Approach)
- Single `PdoDatabase` class hardcoded in `app/Infrastructure/`
- Limited to single database connection
- Manual DSN construction in PHP
- No driver abstraction
- No LetMigrate integration

### After (New Approach)
- **Database Module** (`plugins/Database/`) provides multi-driver support
- Auto-detection of driver from `DB_DRIVER` environment variable
- Driver-specific configuration classes
- Seamless LetMigrate integration
- GDA-compliant module architecture

---

## Bootstrap Changes

### File: `app/bootstrap/base.php`

**Old:**
```php
use App\Infrastructure\PdoDatabase;

->withPorts([
    DatabasePort::class => new PdoDatabase(
        dsn:      $env('DB_DSN', 'sqlite::memory:'),
        username: $env('DB_USERNAME'),
        password: $env('DB_PASSWORD'),
    ),
])
->withModules([
    CommandsProvider::class,
]);
```

**New:**
```php
use Plugins\Database\Provider as DatabaseProvider;

->withPorts([
    // DatabasePort is now provided by the Database Module
])
->withModules([
    DatabaseProvider::class,
    CommandsProvider::class,
]);
```

**Why:** The Database Module is a proper kernel module that provides the DatabasePort interface automatically. No manual port binding needed.

---

## Environment Variables

### Old Approach (`DB_DSN`)
```env
DB_DSN=mysql:host=localhost;port=3306;dbname=mydb
DB_USERNAME=root
DB_PASSWORD=password
```

### New Approach (Driver-Specific)
```env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mydb
DB_USERNAME=root
DB_PASSWORD=password
DB_CHARSET=utf8mb4
```

**Migration:** Update `.env` files to use the new variable naming. Reference `.env.database.example` for complete examples.

---

## Migration Checklist

- [ ] Update `.env` to use new `DB_DRIVER` and `DB_*` variables
- [ ] Reference `.env.database.example` for your driver
- [ ] Remove old `app/Infrastructure/PdoDatabase.php` (no longer used)
- [ ] Run migrations with new Database Module: `php app/cli/run.php migrate:run`
- [ ] Test database queries in your application
- [ ] Verify LetMigrate integration works
- [ ] Test all supported drivers if multi-environment setup

---

## Supported Drivers

| Driver | Env Var | Notes |
|--------|---------|-------|
| MySQL/MariaDB | `DB_DRIVER=mysql` | Production-ready, TCP + socket support |
| PostgreSQL | `DB_DRIVER=postgresql` | Production-ready, full SSL support |
| SQLite | `DB_DRIVER=sqlite` | Development/testing, file or :memory: |
| SQL Server | `DB_DRIVER=sqlsrv` | Requires php-sqlsrv extension |

---

## Configuration Examples

### Development (In-Memory SQLite)
```env
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

### Staging (MySQL with SSL)
```env
DB_DRIVER=mysql
DB_HOST=mysql-staging.example.com
DB_PORT=3306
DB_DATABASE=staging_db
DB_USERNAME=staging_user
DB_PASSWORD=${STAGING_DB_PASSWORD}
DB_CHARSET=utf8mb4
DB_SSL_VERIFY=1
DB_SSL_CA=/etc/ssl/certs/ca-bundle.crt
```

### Production (PostgreSQL with Required SSL)
```env
DB_DRIVER=postgresql
DB_HOST=postgres-prod.example.com
DB_PORT=5432
DB_DATABASE=production_db
DB_USERNAME=prod_user
DB_PASSWORD=${PROD_DB_PASSWORD}
DB_SSL_MODE=require
```

---

## Repository Usage

No changes required to repository code. They already inject `DatabasePort`:

```php
final class UserRepository {
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function findById(string $id): ?array {
        return $this->db->queryOne(
            'SELECT * FROM users WHERE id = ?',
            [$id]
        );
    }
}
```

The `DatabasePort` is now provided by the Database Module instead of the old `PdoDatabase`.

---

## Module Structure

```
plugins/Database/
├── module.json                      # Module metadata
├── Provider.php                     # Driver detection & registration
├── API/
│   └── Contracts/
│       ├── DatabaseConfigurationContract.php
│       └── DatabaseConnectionManagerContract.php
├── Infrastructure/
│   ├── Drivers/
│   │   ├── MySQLConfiguration.php
│   │   ├── PostgreSQLConfiguration.php
│   │   ├── SQLiteConfiguration.php
│   │   └── SqlServerConfiguration.php
│   └── Persistence/
│       └── MultiDriverDatabaseAdapter.php
└── Exceptions/
    └── ConnectionException.php
```

---

## LetMigrate Integration

The Database Module automatically works with LetMigrate. Run migrations using:

```bash
# Run all pending migrations
php app/cli/run.php migrate:run

# Rollback last batch
php app/cli/run.php migrate:rollback

# Check migration status
php app/cli/run.php migrate:status
```

LetMigrate reads the same `DB_DRIVER`, `DB_HOST`, `DB_PORT`, etc. environment variables to connect.

---

## Error Handling

Database errors are translated to `ConnectionException`:

```php
use Plugins\Database\Exceptions\ConnectionException;

try {
    $results = $db->query('SELECT * FROM users');
} catch (ConnectionException $e) {
    // $e->driver — 'mysql', 'pgsql', 'sqlite', 'sqlsrv'
    // $e->operation — 'query', 'execute', 'connect', etc.
    // $e->getMessage() — detailed error message
    // $e->getPrevious() — original PDOException
    throw new RepositoryException('Failed to fetch users', previous: $e);
}
```

---

## Testing

### In-Memory SQLite (Recommended for Tests)
```env
# .env.testing
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

Benefits:
- Fast test execution
- No external dependencies
- Automatic cleanup between tests
- Perfect for CI/CD pipelines

---

## Troubleshooting

### "Unsupported database driver: mysq" Error
**Cause:** Typo in `DB_DRIVER` environment variable.  
**Solution:** Check spelling. Valid values: `mysql`, `postgresql`, `sqlite`, `sqlsrv`

```bash
# ❌ Wrong
DB_DRIVER=mysq

# ✅ Correct
DB_DRIVER=mysql
```

### "Failed to connect" Error
**Cause:** Database server unreachable or credentials incorrect.  
**Solution:** Test connection manually.

**MySQL:**
```bash
mysql -h localhost -u root -p -e "SELECT 1"
```

**PostgreSQL:**
```bash
psql -h localhost -U postgres -c "SELECT 1"
```

**SQLite:**
```bash
sqlite3 /path/to/database.db "SELECT 1"
```

### SSL Certificate Verification Failed
**Cause:** SSL mode too strict for self-signed certificates.  
**Solution:** Adjust SSL settings in `.env`.

```env
# PostgreSQL: use 'prefer' instead of 'require'
DB_SSL_MODE=prefer

# MySQL: disable SSL verification
DB_SSL_VERIFY=0

# SQL Server: trust self-signed cert (dev only!)
DB_TRUST_SERVER_CERT=1
```

---

## Performance Notes

### Connection Pooling
The Database Module is designed to support future connection pooling implementations. For now, single connections are created per request lifecycle.

### Query Optimization
- Use parameterized queries (automatic via PDO)
- Prepared statements are cached by PDO
- No N+1 queries in framework code

### Driver-Specific Features
- **MySQL:** UTF-8MB4 charset by default for emoji support
- **PostgreSQL:** Native JSON operators, full-text search
- **SQLite:** WAL mode for better concurrency
- **SQL Server:** Encryption and bulk operations supported

---

## Backward Compatibility

The old `app/Infrastructure/PdoDatabase.php` is **no longer used** and can be safely deleted:

```bash
rm app/Infrastructure/PdoDatabase.php
```

If you were importing it in your code (unlikely, as repositories should inject `DatabasePort`), replace with:

```php
// ❌ Old
use App\Infrastructure\PdoDatabase;

// ✅ New
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
```

---

## Additional Resources

- [Database Module Documentation](./DATABASE_MODULE_DOCUMENTATION.md) — Complete API reference
- [Environment Variables Guide](./.env.database.example) — All configuration options
- [LetMigrate Integration](./docs/ai-context/18_MIGRATIONS.md) — Migration patterns
- [GDA Architecture](./CLAUDE.md) — Framework architecture principles

---

## Summary

✅ **Database Module fully integrated into framework bootstrap**  
✅ **Multi-driver support: MySQL, PostgreSQL, SQLite, SQL Server**  
✅ **Environment-driven configuration via `DB_*` variables**  
✅ **Seamless LetMigrate integration**  
✅ **GDA-compliant module architecture**  
✅ **Production-ready with SSL/TLS support**  

**Next Steps:**
1. Update `.env` to use new `DB_*` variables
2. Remove old `PdoDatabase.php` from codebase
3. Run migrations to verify integration
4. Test database operations across all supported drivers
