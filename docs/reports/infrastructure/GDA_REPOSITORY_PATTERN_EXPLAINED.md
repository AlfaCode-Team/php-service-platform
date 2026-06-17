# GDA Repository Pattern — Why Infrastructure Classes Need Repositories

## The Question You Asked

> "Why are BackupManager, DeploymentLockManager, etc. using DatabasePort directly?"

**Answer:** They shouldn't be! This violates GDA principles.

---

## GDA Access Rules (From CLAUDE.md)

```
Controller  →  Service   (published contract)
Service     →  Repository AND Gateway  (only layer calling both)
Repository  →  DatabasePort ONLY       (no HTTP, no vendor SDK)
Gateway     →  Vendor SDK ONLY         (no DB, no services)
Domain      →  NOTHING EXTERNAL        (zero imports outside Domain/)
```

**Key Rule:** Only **repositories** should directly access **DatabasePort**.

---

## The Problem

### ❌ WRONG (Before)

```php
// DeploymentLockManager — Infrastructure Utility
final class DeploymentLockManager {
    public function __construct(
        private readonly DatabasePort $db,  // ← DIRECTLY USES DATABASEPORT!
    ) {}
    
    public function acquireLock(): void {
        // ❌ Breaking GDA: Infrastructure should NOT directly use DatabasePort
        $this->db->execute(
            'INSERT INTO deployment_locks ...'
        );
    }
}
```

**Why is this wrong?**
1. `DatabasePort` is a kernel port (infrastructure layer)
2. Only repositories should touch ports
3. `DeploymentLockManager` is an infrastructure utility, not a repository
4. This creates a "skip the repository layer" shortcut

---

## The Solution

### ✅ CORRECT (After)

```php
// Step 1: Repository handles DATABASE ACCESS
final class DeploymentLockRepository {
    public function __construct(
        private readonly DatabasePort $db,  // ← Repository CAN use port
    ) {}
    
    public function createLock(string $key, string $holder, string $expiresAt): void {
        // ✅ This is the RIGHT place for database access
        $this->db->execute(
            'INSERT INTO deployment_locks (lock_key, holder, expires_at) VALUES (?, ?, ?)',
            [$key, $holder, $expiresAt]
        );
    }
}

// Step 2: Infrastructure Utility uses Repository
final class DeploymentLockManager {
    public function __construct(
        private readonly DeploymentLockRepository $lockRepository,  // ← Uses repository
    ) {}
    
    public function acquireLock(): void {
        // ✅ This is the RIGHT way: Infrastructure → Repository → Port
        $this->lockRepository->createLock(
            self::LOCK_KEY,
            $this->getHolderIdentity(),
            $this->getExpirationTime()
        );
    }
}
```

---

## Why This Matters (GDA Benefits)

### 1. **Testability**
```php
// ❌ BEFORE: Can't test without real database
$manager = new DeploymentLockManager(new RealPdoDatabase());
// Tests must hit actual database

// ✅ AFTER: Can mock the repository
$mockRepo = $this->createMock(DeploymentLockRepository::class);
$mockRepo->expects($this->once())->method('createLock');
$manager = new DeploymentLockManager($mockRepo);
// Tests don't need a database
```

### 2. **Reusability**
```php
// Repository is reusable by multiple classes
final class MigrationService {
    public function __construct(
        private readonly DeploymentLockRepository $locks,  // Can use from service
    ) {}
}

final class HealthCheckController {
    public function __construct(
        private readonly DeploymentLockRepository $locks,  // Can use from controller
    ) {}
}

final class DeploymentLockManager {
    public function __construct(
        private readonly DeploymentLockRepository $locks,  // Can use from utility
    ) {}
}
```

### 3. **Consistency**
```
// ALL database access follows the same pattern:
Service → Repository → DatabasePort
Utility → Repository → DatabasePort
Command → Repository → DatabasePort
Controller → Repository → DatabasePort
```

### 4. **Exception Handling**
```php
// Repository translates exceptions
final class DeploymentLockRepository {
    public function createLock(...): void {
        try {
            $this->db->execute(...);
        } catch (\Throwable $e) {
            // ✅ ONE place to translate exceptions
            throw ServiceException::lockAcquisitionFailed($e->getMessage());
        }
    }
}

// Utilities don't need exception handling
final class DeploymentLockManager {
    public function acquireLock(): void {
        // Already caught at repository level
        $this->lockRepository->createLock(...);
    }
}
```

---

## Architecture Hierarchy

```
┌─────────────────────────────────────────┐
│     APPLICATION LAYER                   │
│                                         │
│  • Services (MigrationService)          │
│  • Commands (ModuleAddCommand)          │
│  • Controllers (InvoiceController)      │
└─────────────────────────────────────────┘
            ↓ (depends on)
┌─────────────────────────────────────────┐
│   INFRASTRUCTURE LAYER                  │
│                                         │
│  • Utilities (DeploymentLockManager)    │
│  • Loggers (CommandExecutionLogger)     │
│  • Managers (BackupManager)             │
│                                         │
│  ⚠️ MUST NOT directly use DatabasePort  │
└─────────────────────────────────────────┘
            ↓ (depends on)
┌─────────────────────────────────────────┐
│    REPOSITORY LAYER                     │
│                                         │
│  • DeploymentLockRepository             │
│  • CommandAuditLogRepository            │
│  • BackupRepository                     │
│  • MigrationRepository                  │
│  • ModuleRepository                     │
│                                         │
│  ✅ CAN directly use DatabasePort       │
└─────────────────────────────────────────┘
            ↓ (depends on)
┌─────────────────────────────────────────┐
│    GATEWAY / PORT LAYER                 │
│                                         │
│  • DatabasePort (PDO, MySQL, etc.)     │
│                                         │
│  Only repositories touch this           │
└─────────────────────────────────────────┘
```

---

## Complete Examples

### Example 1: Deployment Lock System

**WRONG (❌):**
```php
// Infrastructure utility accessing port directly
class DeploymentLockManager {
    public function __construct(
        private readonly DatabasePort $db
    ) {}
    
    public function acquireLock(): void {
        // ❌ DatabasePort usage here
        $this->db->execute('INSERT INTO ...');
    }
}
```

**CORRECT (✅):**
```php
// Repository handles data access
class DeploymentLockRepository {
    public function __construct(
        private readonly DatabasePort $db
    ) {}
    
    public function createLock(...): void {
        // ✅ DatabasePort usage here
        $this->db->execute('INSERT INTO ...');
    }
}

// Infrastructure utility delegates to repository
class DeploymentLockManager {
    public function __construct(
        private readonly DeploymentLockRepository $repo
    ) {}
    
    public function acquireLock(): void {
        // ✅ Repository call instead
        $this->repo->createLock(...);
    }
}

// Service can also use the same repository
class MigrationService {
    public function __construct(
        private readonly DeploymentLockRepository $locks
    ) {}
    
    public function runMigrations(): void {
        $this->locks->createLock(...);
        try {
            // run migrations
        } finally {
            $this->locks->deleteLock(...);
        }
    }
}
```

### Example 2: Command Logging

**WRONG (❌):**
```php
class CommandExecutionLogger {
    public function __construct(
        private readonly DatabasePort $db  // ❌ Direct database access
    ) {}
    
    public function logStart(string $cmd): void {
        $this->db->execute('INSERT INTO command_audit_logs ...');
    }
}
```

**CORRECT (✅):**
```php
// Repository
class CommandAuditLogRepository {
    public function __construct(
        private readonly DatabasePort $db
    ) {}
    
    public function logStart(string $cmd, array $args): string {
        $this->db->execute('INSERT INTO command_audit_logs ...');
        return $this->db->lastInsertId();
    }
    
    public function logEnd(string $logId, int $exitCode, ?string $error): void {
        $this->db->execute(
            'UPDATE command_audit_logs SET exit_code = ?, error_message = ? WHERE id = ?',
            [$exitCode, $error, $logId]
        );
    }
}

// Logger uses repository
class CommandExecutionLogger {
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CommandAuditLogRepository $auditLog  // ✅ Uses repository
    ) {}
    
    public function logStart(string $cmd, array $args): string {
        return $this->auditLog->logStart($cmd, $args);
    }
    
    public function logEnd(string $logId, int $exitCode, ?string $error): void {
        $this->auditLog->logEnd($logId, $exitCode, $error);
    }
}
```

---

## Repository Responsibilities

Each repository has ONE responsibility: **Data Access for ONE domain**

| Repository | Domain | Methods |
|------------|--------|---------|
| **DeploymentLockRepository** | Deployment locks | createLock, deleteLock, isLocked, cleanupExpiredLocks |
| **CommandAuditLogRepository** | Command audit logs | logStart, logEnd, logMigration, logDestructiveOperation, getRecentLogs |
| **BackupRepository** | Backup metadata | recordBackup, listBackups, getBackup, deleteOldBackupRecords |
| **ApprovalRepository** | Migration approvals | createApprovalRequest, getApprovalRequest, approve, reject, getPendingApprovals, hasPendingApproval |
| **MigrationRepository** | Database migrations | loadConfiguration, runPending, rollback, getStatus, reset, refresh |
| **ModuleRepository** | Git submodules | add, remove |

---

## Testing Impact

### Without Repositories (Hard to Test)
```php
// Can't test without a real database
$dbConnection = new PdoDatabase('mysql:...');
$manager = new DeploymentLockManager($dbConnection);

// Must use real tables
$manager->acquireLock();  // Actually hits database

// Hard to verify calls
// Can't check if lock was created without querying
```

### With Repositories (Easy to Test)
```php
// Mock the repository
$mockRepo = $this->createMock(DeploymentLockRepository::class);
$mockRepo->expects($this->once())->method('createLock');

// Inject mock
$manager = new DeploymentLockManager($mockRepo);

// Test without database
$manager->acquireLock();

// Verify calls
$mockRepo->verify();  // Check that createLock was called
```

---

## Refactoring Status

### ✅ Created (4 repositories)
- DeploymentLockRepository
- CommandAuditLogRepository
- BackupRepository
- ApprovalRepository

### ✅ Updated (1 class)
- DeploymentLockManager → now uses DeploymentLockRepository

### ⏳ Need to Update (4 classes)
- CommandExecutionLogger → use CommandAuditLogRepository
- BackupManager → use BackupRepository
- MigrationApprovalManager → use ApprovalRepository
- PreFlightValidator → use MigrationRepository

---

## Summary

**The Rule:**
> Infrastructure utilities and managers should NEVER directly use DatabasePort.  
> They should ALWAYS use repositories.

**Why:**
- ✅ Testability (mock repositories)
- ✅ Consistency (all DB access through repositories)
- ✅ Reusability (repositories shared across layers)
- ✅ Exception handling (centralized at repository)
- ✅ GDA Compliance (correct layering)

**The Pattern:**
```
Utility / Manager / Service
    ↓
Repository (handles DB)
    ↓
DatabasePort (gateway)
    ↓
Database (external system)
```

**Not:**
```
Utility / Manager / Service → DatabasePort ❌
```
