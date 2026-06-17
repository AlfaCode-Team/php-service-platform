# Infrastructure Classes — GDA Repository Refactoring

## The Problem

**Before (❌ Violates GDA):**
```php
// DeploymentLockManager directly uses DatabasePort
final class DeploymentLockManager {
    public function __construct(
        private readonly DatabasePort $db,  // ← WRONG: Gateway dependency
    ) {}
    
    public function acquireLock(): void {
        $this->db->execute('INSERT INTO deployment_locks ...');  // ← Direct DB access
    }
}
```

**GDA Violation:** Only repositories should access DatabasePort, not infrastructure utilities.

---

## The Solution

**After (✅ Follows GDA):**
```php
// DeploymentLockManager uses repository
final class DeploymentLockManager {
    public function __construct(
        private readonly DeploymentLockRepository $lockRepository,  // ← Repository layer
    ) {}
    
    public function acquireLock(): void {
        $this->lockRepository->createLock(self::LOCK_KEY, $holder, $expiresAt);  // ← Repository call
    }
}

// Repository handles ALL database access
final class DeploymentLockRepository {
    public function __construct(
        private readonly DatabasePort $db,  // ← CORRECT: Repository has port
    ) {}
    
    public function createLock(string $lockKey, string $holder, string $expiresAt): void {
        $this->db->execute('INSERT INTO deployment_locks ...');  // ← Safe here
    }
}
```

---

## New Repositories Created ✅

### 1. DeploymentLockRepository ✅
**Status:** CREATED  
**File:** `plugins/Commands/Infrastructure/Persistence/DeploymentLockRepository.php`  
**Methods:**
- `isLocked(string $lockKey): bool`
- `getLockHolder(string $lockKey): ?string`
- `createLock(string $lockKey, string $holder, string $expiresAt): void`
- `deleteLock(string $lockKey): void`
- `cleanupExpiredLocks(): void`

### 2. CommandAuditLogRepository ✅
**Status:** CREATED  
**File:** `plugins/Commands/Infrastructure/Persistence/CommandAuditLogRepository.php`  
**Methods:**
- `logStart(string $command, string $user, string $hostname, int $pid, array $arguments): string`
- `logEnd(string $logId, int $exitCode, int $durationMs, ?string $errorMessage): void`
- `logMigration(string $logId, string $migrationName, string $direction, bool $success, ?string $errorMessage): void`
- `logDestructiveOperation(string $logId, string $operationName, string $details): void`
- `getRecentLogs(int $limit = 20): array`

### 3. BackupRepository ✅
**Status:** CREATED  
**File:** `plugins/Commands/Infrastructure/Persistence/BackupRepository.php`  
**Methods:**
- `recordBackup(string $database, string $backupPath, string $filename, int $fileSizeBytes): void`
- `listBackups(string $database, int $limit = 10): array`
- `getBackup(string $filename): ?array`
- `deleteOldBackupRecords(int $daysOld = 30): int`

### 4. ApprovalRepository ✅
**Status:** CREATED  
**File:** `plugins/Commands/Infrastructure/Persistence/ApprovalRepository.php`  
**Methods:**
- `createApprovalRequest(string $approvalId, array $migrations, string $requester): void`
- `getApprovalRequest(string $approvalId): ?array`
- `approve(string $approvalId, string $approver, ?string $notes): void`
- `reject(string $approvalId, string $rejector, string $reason): void`
- `getPendingApprovals(): array`
- `hasPendingApproval(int $timeoutSeconds = 3600): bool`

---

## Infrastructure Classes to Update

### 1. DeploymentLockManager ✅
**Status:** PARTIALLY UPDATED  
**What Changed:**
- ❌ OLD: `private readonly DatabasePort $db`
- ✅ NEW: `private readonly DeploymentLockRepository $lockRepository`

**Methods Updated:**
- `acquireLock()` — use `$this->lockRepository->createLock()`
- `releaseLock()` — use `$this->lockRepository->deleteLock()`
- `isLocked()` — use `$this->lockRepository->isLocked()`

**Methods to Remove:**
- `getLockHolder()` — now handled by repository
- `cleanupExpiredLocks()` — now handled by repository

---

### 2. CommandExecutionLogger ⏳
**Status:** NEEDS REFACTORING  
**Current Issue:**
```php
// ❌ WRONG: Direct DatabasePort usage
final class CommandExecutionLogger {
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DatabasePort $db,  // ← Should use repository
    ) {}
    
    public function logStart(string $command, array $argv): void {
        $this->db->execute('INSERT INTO command_audit_logs ...');
    }
}
```

**Required Changes:**
```php
// ✅ CORRECT: Use repository
final class CommandExecutionLogger {
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CommandAuditLogRepository $auditRepository,  // ← Repository
    ) {}
    
    public function logStart(string $command, array $argv): void {
        return $this->auditRepository->logStart($command, get_current_user(), ...);
    }
}
```

---

### 3. BackupManager ⏳
**Status:** NEEDS REFACTORING  
**Current Issue:**
```php
// ❌ WRONG: Direct DatabasePort usage
final class BackupManager {
    public function __construct(
        private readonly DatabasePort $db,  // ← Should use repository
    ) {}
}
```

**Required Changes:**
```php
// ✅ CORRECT: Use repository
final class BackupManager {
    public function __construct(
        private readonly BackupRepository $backupRepository,  // ← Repository
    ) {}
}
```

---

### 4. MigrationApprovalManager ⏳
**Status:** NEEDS REFACTORING  
**Current Issue:**
```php
// ❌ WRONG: Direct DatabasePort usage
final class MigrationApprovalManager {
    public function __construct(
        private readonly DatabasePort $db,  // ← Should use repository
    ) {}
}
```

**Required Changes:**
```php
// ✅ CORRECT: Use repository
final class MigrationApprovalManager {
    public function __construct(
        private readonly ApprovalRepository $approvalRepository,  // ← Repository
    ) {}
}
```

---

### 5. PreFlightValidator ⏳
**Status:** NEEDS REFACTORING  
**Current Issue:**
```php
// ❌ WRONG: Direct DatabasePort usage
final class PreFlightValidator {
    public function __construct(
        private readonly DatabasePort $db,  // ← Should use repository
    ) {}
}
```

**Required Changes:**
```php
// ✅ CORRECT: Use repository or MigrationRepository
final class PreFlightValidator {
    public function __construct(
        private readonly MigrationRepository $migrationRepository,  // ← Use existing repo
    ) {}
}
```

---

## Updated Architecture

```
┌─────────────────────────────────────────┐
│     DeploymentLockManager                │
│  (Infrastructure Utility)                │
│                                         │
│  public function acquireLock(): void {   │
│      $this->lockRepository->...          │
│  }                                       │
└─────────────────────────────────────────┘
              ↓ (depends on)
┌─────────────────────────────────────────┐
│  DeploymentLockRepository                │
│  (Repository - Data Access)              │
│                                         │
│  public function createLock(): void {    │
│      $this->db->execute(...)             │
│  }                                       │
└─────────────────────────────────────────┘
              ↓ (depends on)
┌─────────────────────────────────────────┐
│  DatabasePort                            │
│  (Gateway - Vendor Adapter)              │
│                                         │
│  public function execute(string $sql)    │
└─────────────────────────────────────────┘
```

---

## Dependency Injection Tree (After Refactoring)

```
Provider.register(ModuleContainer $c)
    │
    ├→ DeploymentLockRepository
    │   └→ depends: DatabasePort (from kernel)
    │
    ├→ CommandAuditLogRepository
    │   └→ depends: DatabasePort (from kernel)
    │
    ├→ BackupRepository
    │   └→ depends: DatabasePort (from kernel)
    │
    ├→ ApprovalRepository
    │   └→ depends: DatabasePort (from kernel)
    │
    ├→ DeploymentLockManager
    │   └→ depends: DeploymentLockRepository
    │
    ├→ CommandExecutionLogger
    │   └→ depends:
    │       • LoggerInterface (from kernel)
    │       • CommandAuditLogRepository
    │
    ├→ BackupManager
    │   └→ depends: BackupRepository
    │
    ├→ MigrationApprovalManager
    │   └→ depends: ApprovalRepository
    │
    └→ PreFlightValidator
        └→ depends: MigrationRepository
```

---

## GDA Compliance After Refactoring

| Layer | Before | After |
|-------|--------|-------|
| **Infrastructure Utilities** | Use DatabasePort directly ❌ | Use Repositories ✅ |
| **Repositories** | Don't exist ❌ | Handle all DB access ✅ |
| **DatabasePort** | Everywhere ❌ | Only in repositories ✅ |
| **Exception Translation** | Scattered ❌ | At repository level ✅ |
| **Testability** | Hard (DB deps) ❌ | Easy (mock repos) ✅ |

---

## Implementation Checklist

- [x] Create DeploymentLockRepository
- [x] Create CommandAuditLogRepository
- [x] Create BackupRepository
- [x] Create ApprovalRepository
- [x] Update DeploymentLockManager to use repository
- [ ] Update CommandExecutionLogger to use repository
- [ ] Update BackupManager to use repository
- [ ] Update MigrationApprovalManager to use repository
- [ ] Update PreFlightValidator to use repository
- [ ] Update Provider.php to inject repositories
- [ ] Test all infrastructure classes with mocked repositories

---

## Benefits After Refactoring

✅ **No Infrastructure Utilities Access DatabasePort Directly**  
✅ **All Database Access Centralized in Repositories**  
✅ **Clear GDA Compliance: Infrastructure → Repository → DatabasePort**  
✅ **Easier Testing: Mock repositories instead of ports**  
✅ **Better Error Handling: Exception translation at repository layer**  
✅ **Reusability: Repositories can be used by services + commands**  

---

## Summary

**Created 4 New Repositories:**
1. DeploymentLockRepository
2. CommandAuditLogRepository
3. BackupRepository
4. ApprovalRepository

**Updated 1 Infrastructure Class:**
1. DeploymentLockManager ✅

**Need to Update 4 Infrastructure Classes:**
1. CommandExecutionLogger
2. BackupManager
3. MigrationApprovalManager
4. PreFlightValidator

**Status:** Core refactoring complete, implementation in progress
