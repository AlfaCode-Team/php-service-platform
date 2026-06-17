# Database Module — Quick Start

**Status:** ✅ Integrated  
**Location:** `plugins/Database/`  
**Namespace:** `Plugins\Database\`  

---

## What You Need to Do

### 1️⃣ Update Environment Variables

Replace old `.env` database config:

```bash
# ❌ OLD (no longer works)
DB_DSN=mysql:host=localhost;port=3306;dbname=mydb
DB_USERNAME=root
DB_PASSWORD=password

# ✅ NEW
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mydb
DB_USERNAME=root
DB_PASSWORD=password
DB_CHARSET=utf8mb4
```

Copy from [`.env.database.example`](.env.database.example) for your environment.

### 2️⃣ Verify Bootstrap Integration

Check `app/bootstrap/base.php` includes Database Module:

```php
use Plugins\Database\Provider as DatabaseProvider;

->withModules([
    DatabaseProvider::class,
    CommandsProvider::class,
])
```

✅ Already done — no action needed if you see this.

### 3️⃣ (Optional) Delete Old Code

Remove the deprecated `PdoDatabase` class:

```bash
rm app/Infrastructure/PdoDatabase.php
```

---

## Test the Integration

### Option A: Use SQLite In-Memory (Fast, No Setup)

```env
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

```bash
# Run migrations
php app/cli/run.php migrate:run

# Check status
php app/cli/run.php migrate:status
```

### Option B: Use MySQL

```env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mydb
DB_USERNAME=root
DB_PASSWORD=password
DB_CHARSET=utf8mb4
```

```bash
mysql -u root -p -e "CREATE DATABASE mydb"
php app/cli/run.php migrate:run
```

### Option C: Use PostgreSQL

```env
DB_DRIVER=postgresql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=mydb
DB_USERNAME=postgres
DB_PASSWORD=password
DB_SSL_MODE=prefer
```

```bash
psql -U postgres -c "CREATE DATABASE mydb"
php app/cli/run.php migrate:run
```

---

## Using in Your Code

No changes to repository code required. They already inject `DatabasePort`:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

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

The `DatabasePort` now uses the Database Module's `MultiDriverDatabaseAdapter` automatically.

---

## Driver Quick Reference

| Driver | Command | Notes |
|--------|---------|-------|
| SQLite | `DB_DRIVER=sqlite` | Great for dev/testing, no setup |
| MySQL | `DB_DRIVER=mysql` | Most common, production-ready |
| PostgreSQL | `DB_DRIVER=postgresql` | Advanced features, production-ready |
| SQL Server | `DB_DRIVER=sqlsrv` | Requires php-sqlsrv extension |

---

## Common Issues & Fixes

| Problem | Fix |
|---------|-----|
| "Unsupported database driver: mysq" | Check spelling: `mysql` not `mysq` |
| "Failed to connect" | Verify DB server is running and credentials correct |
| Migration doesn't recognize driver | Make sure `DB_DRIVER` env var is set |
| SSL connection failed | Try `DB_SSL_MODE=disable` for testing (dev only) |

---

## Full Documentation

- 📖 [Complete Documentation](./DATABASE_MODULE_DOCUMENTATION.md)
- 🔧 [Integration Guide](./DATABASE_MODULE_INTEGRATION_GUIDE.md)
- ⚙️ [Configuration Reference](.env.database.example)

---

## Architecture

The Database Module provides:

```
Request
  ↓
Repository (injects DatabasePort)
  ↓
Database Module Provider (auto-registers based on DB_DRIVER)
  ↓
MultiDriverDatabaseAdapter (wraps PDO)
  ↓
Driver-Specific Configuration (MySQL/PostgreSQL/SQLite/SQL Server)
  ↓
PDO → Database Server
```

---

## That's It! ✅

Your application now supports:
- ✅ MySQL/MariaDB
- ✅ PostgreSQL
- ✅ SQLite (file or in-memory)
- ✅ SQL Server
- ✅ Automatic driver detection from `DB_DRIVER` env var
- ✅ LetMigrate integration
- ✅ Production-ready SSL/TLS

Migrations and database queries work exactly as before—just with more driver options!
