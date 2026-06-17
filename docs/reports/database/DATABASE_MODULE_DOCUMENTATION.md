# Database Module — Multi-Driver Architecture

**Location:** `plugins/Database/`  
**Status:** ✅ Enterprise-Ready  
**Supported Drivers:** MySQL, PostgreSQL, SQLite, SQL Server  

---

## Overview

The Database Module is an enterprise-grade database abstraction layer that implements the **DatabasePort** kernel interface with support for multiple database drivers. It decouples the framework from specific database implementations while providing driver-optimized configuration and seamless LetMigrate integration.

### Key Principles

- **Kernel Independence** — implements DatabasePort interface defined in kernel
- **Multi-Driver Support** — MySQL, PostgreSQL, SQLite, SQL Server
- **GDA Compliance** — follows Gated Demand Architecture patterns
- **LetMigrate Ready** — integrates with LetMigrate migration engine
- **Environment-Driven** — configuration via `DB_*` environment variables
- **Production-Safe** — SSL/TLS support, transaction management, connection handling

---

## Architecture

### Layer Structure

```
┌─────────────────────────────────────────┐
│     Provider                            │
│  (Wires all components)                 │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  MultiDriverDatabaseAdapter             │
│  (Implements DatabasePort)              │
│  (Wraps PDO with multi-driver support)  │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  Driver Configuration Classes           │
│  • MySQLConfiguration                   │
│  • PostgreSQLConfiguration              │
│  • SQLiteConfiguration                  │
│  • SqlServerConfiguration               │
└─────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────┐
│  PDO (PHP Data Objects)                 │
│  (Native database library)              │
└─────────────────────────────────────────┘
```

### Class Structure

```
Plugins/Database/
├── module.json
├── Provider.php
│
├── API/
│   └── Contracts/
│       ├── DatabaseConfigurationContract.php  (interface)
│       └── DatabaseConnectionManagerContract.php (interface)
│
├── Infrastructure/
│   ├── Drivers/
│   │   ├── MySQLConfiguration.php
│   │   ├── PostgreSQLConfiguration.php
│   │   ├── SQLiteConfiguration.php
│   │   └── SqlServerConfiguration.php
│   └── Persistence/
│       └── MultiDriverDatabaseAdapter.php
│
└── Exceptions/
    └── ConnectionException.php
```

---

## Supported Drivers

### 1. MySQL / MariaDB

**DSN Format:** `mysql:host=localhost;port=3306;dbname=mydb;charset=utf8mb4`

**Environment Variables:**
```bash
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=mydb
DB_USERNAME=root
DB_PASSWORD=password
DB_CHARSET=utf8mb4
DB_UNIX_SOCKET=/var/run/mysqld/mysqld.sock  (optional)
DB_SSL_VERIFY=0  (optional)
DB_SSL_CA=/path/to/ca.pem  (optional)
```

**Features:**
- TCP/IP and Unix socket support
- SSL/TLS connection security
- Character set configuration
- Auto-reconnect support

### 2. PostgreSQL

**DSN Format:** `pgsql:host=localhost;port=5432;dbname=postgres;sslmode=prefer`

**Environment Variables:**
```bash
DB_DRIVER=postgresql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD=password
DB_SSL_MODE=prefer  (disable|allow|prefer|require|verify-ca|verify-full)
DB_UNIX_SOCKET=/var/run/postgresql  (optional)
```

**Features:**
- Full SSL/TLS support
- Unix socket connections
- Multiple SSL modes for different security levels
- LISTEN/NOTIFY support

### 3. SQLite

**DSN Format:** `sqlite:/path/to/database.db` or `sqlite::memory:`

**Environment Variables:**
```bash
DB_DRIVER=sqlite
DB_DATABASE=/path/to/database.db  (or ':memory:' for in-memory)
```

**Features:**
- File-based and in-memory databases
- Zero-configuration for development
- Embedded databases for testing
- Full ACID compliance

### 4. SQL Server

**DSN Format:** `sqlsrv:Server=localhost,1433;Database=mydb`

**Environment Variables:**
```bash
DB_DRIVER=sqlsrv
DB_HOST=localhost
DB_PORT=1433
DB_DATABASE=mydb
DB_USERNAME=sa
DB_PASSWORD=password
DB_ENCRYPT=1  (optional)
DB_TRUST_SERVER_CERT=1  (optional)
```

**Requirements:**
- PHP SQL Server driver installed (`php-sqlsrv` extension)
- ODBC Driver 17 or 18 for SQL Server

**Features:**
- TCP/IP protocol support
- Connection encryption
- Secure certificate handling

---

## Configuration Examples

### Development (SQLite In-Memory)

```env
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

### Development (SQLite File-Based)

```env
DB_DRIVER=sqlite
DB_DATABASE=storage/dev.sqlite
```

### Staging (MySQL with SSL)

```env
DB_DRIVER=mysql
DB_HOST=mysql.staging.example.com
DB_PORT=3306
DB_DATABASE=staging_db
DB_USERNAME=staging_user
DB_PASSWORD=${STAGING_DB_PASSWORD}
DB_CHARSET=utf8mb4
DB_SSL_VERIFY=1
DB_SSL_CA=/etc/ssl/certs/ca-bundle.crt
```

### Production (PostgreSQL with SSL)

```env
DB_DRIVER=postgresql
DB_HOST=postgres-prod.example.com
DB_PORT=5432
DB_DATABASE=production_db
DB_USERNAME=prod_user
DB_PASSWORD=${PROD_DB_PASSWORD}
DB_SSL_MODE=require
```

### Production (SQL Server)

```env
DB_DRIVER=sqlsrv
DB_HOST=sqlserver.example.com
DB_PORT=1433
DB_DATABASE=prod_db
DB_USERNAME=sa
DB_PASSWORD=${SQLSERVER_PASSWORD}
DB_ENCRYPT=1
DB_TRUST_SERVER_CERT=0
```

---

## LetMigrate Integration

The Database Module is fully compatible with LetMigrate for database migrations.

### Configuration for LetMigrate

```php
// projects/admin/config/let-migrate.php
return [
    'default' => 'default',
    'connections' => [
        'default' => [
            'driver'   => getenv('DB_DRIVER'),
            'host'     => getenv('DB_HOST'),
            'port'     => (int) getenv('DB_PORT'),
            'database' => getenv('DB_DATABASE'),
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
        ],
    ],
    'paths' => [
        __DIR__ . '/../database/migrations',
    ],
    'tracking_table' => 'let_migrations',
];
```

### Running Migrations

```bash
# Run all pending migrations
php app/cli/run.php migrate:run

# Rollback last batch
php app/cli/run.php migrate:rollback

# Get migration status
php app/cli/run.php migrate:status
```

---

## API Reference

### DatabasePort Interface

```php
interface DatabasePort {
    public function query(string $sql, array $params = []): array;
    public function queryOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;
    public function lastInsertId(): string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}
```

### Usage in Repositories

```php
final class UserRepository {
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function findById(string $id): ?array {
        return $this->db->queryOne(
            'SELECT * FROM users WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );
    }

    public function create(array $data): string {
        $this->db->execute(
            'INSERT INTO users (name, email, created_at) VALUES (?, ?, ?)',
            [$data['name'], $data['email'], date('Y-m-d H:i:s')]
        );
        return $this->db->lastInsertId();
    }
}
```

### Advanced: Direct PDO Access

```php
// For advanced operations, access PDO directly
$adapter = $container->make(MultiDriverDatabaseAdapter::class);
$pdo = $adapter->pdo();
$driver = $adapter->driver();  // 'mysql', 'pgsql', etc.
```

---

## Error Handling

All database errors are translated to `ConnectionException`:

```php
try {
    $db->query('SELECT * FROM users');
} catch (ConnectionException $e) {
    // $e->driver — 'mysql', 'pgsql', etc.
    // $e->operation — 'query', 'execute', 'connect', etc.
    // $e->getMessage() — detailed error message
    // $e->getPrevious() — original PDOException
}
```

---

## Performance & Optimization

### Connection Pooling (Future)

The module is designed to support connection pooling implementations:

```php
// Future enhancement
$container->singleton(DatabaseConnectionManagerContract::class, fn() =>
    new ConnectionPool(defaultConfig)
);
```

### Driver-Specific Optimizations

#### MySQL
- Prepared statement caching
- Connection pooling support
- UTF-8MB4 charset by default

#### PostgreSQL
- Native JSON support
- LISTEN/NOTIFY capabilities
- Full-text search

#### SQLite
- In-memory for testing
- Zero-configuration
- WAL mode for concurrency

#### SQL Server
- Connection encryption
- Bulk operations
- Windows authentication (optional)

---

## Security Best Practices

✅ **Do:**
- Use environment variables for credentials (`DB_PASSWORD`, `DB_USERNAME`)
- Enable SSL/TLS in production (`DB_SSL_MODE=require`)
- Use parameterized queries (framework handles this)
- Rotate credentials regularly
- Use strong passwords (20+ chars, mixed case, symbols)

❌ **Don't:**
- Hardcode credentials in code
- Use `disable` mode for SSL in production
- Trust self-signed certificates without verification
- Store `.env` in version control
- Use default credentials (change `sa` password for SQL Server)

---

## Testing & Development

### In-Memory SQLite (Recommended for Tests)

```env
DB_DRIVER=sqlite
DB_DATABASE=:memory:
```

Benefits:
- Fast test execution
- No external dependencies
- Automatic cleanup between tests
- Perfect for CI/CD

### Docker Compose Example

```yaml
version: '3.8'
services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root_password
      MYSQL_DATABASE: test_db
    ports:
      - "3306:3306"

  postgres:
    image: postgres:15
    environment:
      POSTGRES_PASSWORD: postgres_password
      POSTGRES_DB: test_db
    ports:
      - "5432:5432"

  sqlserver:
    image: mcr.microsoft.com/mssql/server:2022-latest
    environment:
      SA_PASSWORD: YourPassword123!
      ACCEPT_EULA: Y
    ports:
      - "1433:1433"
```

---

## Migration Guide

### From Old PdoDatabase

**Before:**
```php
// app/Infrastructure/PdoDatabase.php
final class PdoDatabase implements DatabasePort {
    public function __construct(
        string $dsn = 'sqlite::memory:',
        ?string $username = null,
        ?string $password = null,
    ) {
        $this->pdo = new PDO($dsn, $username, $password, [...]);
    }
}
```

**After:**
```php
// No need to create custom adapter — use Database Module
use Plugins\Database\Provider;

// In bootstrap/app.php
$kernel = Kernel::configure()
    ->withModules([
        Plugins\Database\Provider::class,  // ← automatic
        // ... other modules
    ])
```

**Benefits:**
- ✅ Multi-driver support built-in
- ✅ Driver-specific optimizations
- ✅ Less boilerplate code
- ✅ Automatic configuration via ENV vars
- ✅ Enterprise-grade error handling

---

## Troubleshooting

### "Unsupported database driver" Error

```
InvalidArgumentException: Unsupported database driver: mysq
```

**Solution:** Check `DB_DRIVER` environment variable is spelled correctly.

```bash
# Valid values:
DB_DRIVER=mysql          # MySQL/MariaDB
DB_DRIVER=postgresql     # PostgreSQL
DB_DRIVER=sqlite         # SQLite
DB_DRIVER=sqlsrv         # SQL Server
```

### "Failed to connect" Error

**MySQL:**
```bash
# Check MySQL is running
mysql -h localhost -u root -p -e "SELECT 1"
```

**PostgreSQL:**
```bash
# Check PostgreSQL is running
psql -h localhost -U postgres -c "SELECT 1"
```

**SQL Server:**
```bash
# Verify SQL Server driver installed
php -m | grep sqlsrv
```

### Character Encoding Issues (MySQL)

```bash
# Set correct charset
DB_CHARSET=utf8mb4
```

### SSL/TLS Connection Failures

```bash
# Disable SSL verification (development only)
DB_SSL_MODE=disable
# or
DB_SSL_VERIFY=0
```

---

## Summary

The Database Module provides:

✅ **Multi-driver support** — MySQL, PostgreSQL, SQLite, SQL Server  
✅ **GDA compliance** — kernel port interface implementation  
✅ **LetMigrate ready** — seamless migration integration  
✅ **Production-safe** — SSL/TLS, transactions, error handling  
✅ **Zero config** — environment-driven, sensible defaults  
✅ **Testable** — in-memory SQLite for fast tests  
✅ **Enterprise** — driver-specific optimizations, connection handling  

Ready for production use across all major database platforms.
