# Database Module — Verification Checklist

Use this checklist to verify the Database Module is properly integrated and working.

---

## Pre-Integration Checks

### File System
- [ ] Database Module exists at `plugins/Database/`
- [ ] All 10 module files present (see file list below)
- [ ] Documentation files created in root directory

**Files to verify:**
```bash
plugins/Database/
├── module.json
├── Provider.php
├── API/Contracts/
│   ├── DatabaseConfigurationContract.php
│   └── DatabaseConnectionManagerContract.php
├── Infrastructure/Drivers/
│   ├── MySQLConfiguration.php
│   ├── PostgreSQLConfiguration.php
│   ├── SQLiteConfiguration.php
│   └── SqlServerConfiguration.php
├── Infrastructure/Persistence/
│   └── MultiDriverDatabaseAdapter.php
└── Exceptions/
    └── ConnectionException.php
```

### Documentation
- [ ] `DATABASE_MODULE_DOCUMENTATION.md` exists
- [ ] `DATABASE_MODULE_INTEGRATION_GUIDE.md` exists
- [ ] `DATABASE_MODULE_QUICKSTART.md` exists
- [ ] `DATABASE_MODULE_COMPLETION_SUMMARY.md` exists
- [ ] `.env.database.example` exists

### Bootstrap Integration
- [ ] `app/bootstrap/base.php` imports `Plugins\Database\Provider`
- [ ] `DatabaseProvider::class` in `withModules()`
- [ ] Old `PdoDatabase` binding removed from `withPorts()`
- [ ] Old `app/Infrastructure/PdoDatabase.php` import removed

---

## Environment Setup

### SQLite (Fastest for Testing)
```env
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

- [ ] `.env` file exists in project root
- [ ] `DB_DRIVER` set to one of: `mysql`, `postgresql`, `sqlite`, `sqlsrv`
- [ ] `DB_DATABASE` set appropriately for your driver

### MySQL Configuration (if using MySQL)
```env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mydb
DB_USERNAME=root
DB_PASSWORD=password
DB_CHARSET=utf8mb4
```

- [ ] MySQL server running (if not SQLite)
- [ ] Database created (if required by driver)
- [ ] User account created with proper permissions
- [ ] Connection can be made manually: `mysql -h localhost -u root -p`

### PostgreSQL Configuration (if using PostgreSQL)
```env
DB_DRIVER=postgresql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=password
DB_SSL_MODE=prefer
```

- [ ] PostgreSQL server running (if not SQLite)
- [ ] Database created (if required)
- [ ] Connection can be made manually: `psql -h localhost -U postgres`

---

## Integration Verification

### Autoloading
```bash
php -r "require 'vendor/autoload.php'; echo class_exists('Plugins\\Database\\Provider') ? 'OK' : 'FAIL';"
```
- [ ] Command outputs: `OK`

### Module Registration
```bash
# Check that the Provider class is properly defined
php -r "require 'vendor/autoload.php'; \$p = new \Plugins\Database\Provider(); echo \$p->solves(); echo PHP_EOL;"
```
- [ ] Command outputs: `database.management`

### Bootstrap Loading
```bash
php -r "
require 'vendor/autoload.php';
\$builder = require 'app/bootstrap/base.php';
echo 'Bootstrap loads successfully';
"
```
- [ ] No errors — bootstrap loads
- [ ] No "class not found" warnings

---

## Database Connection Tests

### SQLite
```bash
cd /path/to/project
php -r "
require 'vendor/autoload.php';
putenv('DB_DRIVER=sqlite');
putenv('DB_DATABASE=:memory:');
\$config = new \Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration(':memory:');
echo 'DSN: ' . \$config->dsn() . PHP_EOL;
echo 'Driver: ' . \$config->driver() . PHP_EOL;
"
```
- [ ] Outputs DSN and driver name correctly

### MySQL
```bash
php -r "
require 'vendor/autoload.php';
putenv('DB_DRIVER=mysql');
putenv('DB_HOST=localhost');
putenv('DB_PORT=3306');
putenv('DB_DATABASE=test');
putenv('DB_USERNAME=root');
putenv('DB_PASSWORD=password');
\$config = new \Plugins\Database\Infrastructure\Drivers\MySQLConfiguration(
    'localhost', 3306, 'test', 'root', 'password', 'utf8mb4'
);
echo 'DSN: ' . \$config->dsn() . PHP_EOL;
"
```
- [ ] Outputs correct MySQL DSN
- [ ] No connection errors (yet)

### PostgreSQL
```bash
php -r "
require 'vendor/autoload.php';
\$config = new \Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration(
    'localhost', 5432, 'postgres', 'postgres', 'password'
);
echo 'DSN: ' . \$config->dsn() . PHP_EOL;
"
```
- [ ] Outputs correct PostgreSQL DSN

---

## Migration Tests

### Run Migrations
```bash
php app/cli/run.php migrate:status
```
- [ ] Command succeeds (no "Unsupported database driver" error)
- [ ] Lists migration status or "No migrations found"

### Run Test Migration (if migrations exist)
```bash
php app/cli/run.php migrate:run
```
- [ ] Migrations run successfully
- [ ] No database errors
- [ ] All tables created

### Rollback Test
```bash
php app/cli/run.php migrate:rollback
```
- [ ] Rollback succeeds
- [ ] Tables removed properly

---

## Query Execution Tests

### Create Test File

Create `test_db.php`:
```php
<?php
require 'vendor/autoload.php';

use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;

// SQLite in-memory example
$config = new SQLiteConfiguration(':memory:');
$db = new MultiDriverDatabaseAdapter($config);

// Create test table
$db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
echo "✓ Table created\n";

// Insert test data
$db->execute('INSERT INTO users (name) VALUES (?)', ['Alice']);
echo "✓ Insert successful\n";

// Query test data
$user = $db->queryOne('SELECT * FROM users WHERE name = ?', ['Alice']);
if ($user && $user['name'] === 'Alice') {
    echo "✓ Query successful\n";
} else {
    echo "✗ Query failed\n";
    exit(1);
}

// Transaction test
$db->beginTransaction();
$db->execute('INSERT INTO users (name) VALUES (?)', ['Bob']);
$db->commit();
echo "✓ Transaction successful\n";

echo "\n✅ All tests passed!\n";
```

### Run Test
```bash
php test_db.php
```
- [ ] All checks pass (all ✓ marks visible)
- [ ] No exceptions thrown

---

## Repository Integration Tests

### Inject DatabasePort into Repository

Create test repository:
```php
<?php
namespace App\Test;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

final class TestRepository {
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function testQuery(): bool {
        $result = $this->db->query('SELECT 1 as num');
        return !empty($result);
    }
}
```

- [ ] Repository accepts `DatabasePort` injection
- [ ] Type hints correctly resolve from kernel
- [ ] No "unknown class" IDE errors

---

## Driver-Specific Tests

### MySQL Test
```bash
# Set env vars for MySQL
export DB_DRIVER=mysql
export DB_HOST=localhost
export DB_PORT=3306
export DB_DATABASE=test_db
export DB_USERNAME=root
export DB_PASSWORD=password

# Create database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS test_db"

# Run migrations
php app/cli/run.php migrate:run

# Verify
mysql -u root -p -e "USE test_db; SHOW TABLES;"
```
- [ ] Database created
- [ ] Migrations run successfully
- [ ] Tables visible in MySQL

### PostgreSQL Test
```bash
# Set env vars for PostgreSQL
export DB_DRIVER=postgresql
export DB_HOST=localhost
export DB_PORT=5432
export DB_DATABASE=test_db
export DB_USERNAME=postgres
export DB_PASSWORD=password

# Create database
psql -U postgres -c "CREATE DATABASE test_db"

# Run migrations
php app/cli/run.php migrate:run

# Verify
psql -U postgres -d test_db -c "\dt"
```
- [ ] Database created
- [ ] Migrations run successfully
- [ ] Tables visible in PostgreSQL

### SQL Server Test (if available)
```bash
# Set env vars for SQL Server
export DB_DRIVER=sqlsrv
export DB_HOST=localhost
export DB_PORT=1433
export DB_DATABASE=test_db
export DB_USERNAME=sa
export DB_PASSWORD=YourPassword123!

# Verify driver installed
php -m | grep sqlsrv
# Should output: sqlsrv

# Run migrations
php app/cli/run.php migrate:run
```
- [ ] php-sqlsrv extension installed
- [ ] Migrations run successfully

---

## Error Handling Tests

### Test ConnectionException

Create test file:
```php
<?php
require 'vendor/autoload.php';

use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;
use Plugins\Database\Exceptions\ConnectionException;

try {
    $config = new MySQLConfiguration(
        'invalid-host', 3306, 'test', 'root', 'wrong'
    );
    $db = new MultiDriverDatabaseAdapter($config);
    echo "✗ Should have thrown ConnectionException\n";
} catch (ConnectionException $e) {
    echo "✓ ConnectionException caught\n";
    echo "✓ Driver: " . $e->driver . "\n";
    echo "✓ Operation: " . $e->operation . "\n";
}
```

- [ ] ConnectionException properly thrown
- [ ] Driver and operation properties populated
- [ ] Message is informative

---

## Code Quality Checks

### Static Analysis (if tools available)
```bash
# Check for obvious issues (optional, requires tools)
php -l plugins/Database/Provider.php
php -l plugins/Database/Infrastructure/Persistence/MultiDriverDatabaseAdapter.php
```
- [ ] No syntax errors

### Namespace Verification
```bash
php -r "
require 'vendor/autoload.php';
echo 'Plugins\Database\Provider: ' . (class_exists('Plugins\Database\Provider') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter: ' . (class_exists('Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter') ? 'OK' : 'FAIL') . PHP_EOL;
echo 'Plugins\Database\Exceptions\ConnectionException: ' . (class_exists('Plugins\Database\Exceptions\ConnectionException') ? 'OK' : 'FAIL') . PHP_EOL;
"
```
- [ ] All three classes found and load correctly

---

## Performance Baseline (Optional)

### Query Benchmark
```bash
php -r "
require 'vendor/autoload.php';
\$config = new \Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration(':memory:');
\$db = new \Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter(\$config);

\$db->execute('CREATE TABLE perf_test (id INTEGER PRIMARY KEY, value TEXT)');

\$start = microtime(true);
for (\$i = 0; \$i < 1000; \$i++) {
    \$db->execute('INSERT INTO perf_test (value) VALUES (?)', ['test_'.\$i]);
}
\$time = (microtime(true) - \$start) * 1000;

echo sprintf('Inserted 1000 rows in %.2f ms (%.4f ms/row)', \$time, \$time / 1000) . PHP_EOL;
"
```
- [ ] Completes in reasonable time (should be < 100ms for SQLite)
- [ ] No hang or timeout

---

## Final Verification

### Clean Slate Restart
```bash
# Kill any existing PHP processes
pkill -f php

# Clear any cached autoload
rm -rf vendor/composer/autoload_*.php || true

# Regenerate autoload
composer dump-autoload

# Test bootstrap again
php -r "require 'vendor/autoload.php'; require 'app/bootstrap/base.php'; echo 'Bootstrap OK';"
```
- [ ] Bootstrap loads cleanly
- [ ] No stale cache issues

### Documentation Completeness
- [ ] User can follow DATABASE_MODULE_QUICKSTART.md from start to finish
- [ ] All environment variable examples work
- [ ] Migration commands run successfully
- [ ] All 4 drivers documented with examples

---

## Completion Checklist

### When All Checks Pass:
- [ ] Database Module files in place
- [ ] Documentation complete
- [ ] Bootstrap integration verified
- [ ] Environment variables configured
- [ ] Migrations run successfully
- [ ] Queries execute without errors
- [ ] All 4 drivers tested (at least SQLite)
- [ ] Error handling verified
- [ ] Code quality validated
- [ ] Performance acceptable

### Sign-Off
- [ ] Date verified: ___________
- [ ] Tested by: ___________
- [ ] Ready for production: Yes / No

---

## Troubleshooting

If any check fails, see:
- 📖 [Complete Documentation](./DATABASE_MODULE_DOCUMENTATION.md) — Comprehensive reference
- 🔧 [Integration Guide](./DATABASE_MODULE_INTEGRATION_GUIDE.md) — Detailed migration steps
- ⚡ [Quick Start](./DATABASE_MODULE_QUICKSTART.md) — Fast setup guide
- 📋 [Completion Summary](./DATABASE_MODULE_COMPLETION_SUMMARY.md) — What was delivered

---

**Status: Ready to Verify** ✅
