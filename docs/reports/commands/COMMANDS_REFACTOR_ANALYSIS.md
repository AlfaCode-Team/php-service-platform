# Commands Module Refactoring — GDA Architecture Analysis

## Current State Issues

### 1. **Mixing Concerns**
- Commands directly instantiate ShellResult
- Direct git operations without service abstraction
- Migration commands tightly coupled to LetMigrate

### 2. **Missing Service Layer**
- No service layer between commands and repositories
- Commands should not access external systems directly
- Enterprise safeguards (locks, backups, approval) not integrated

### 3. **LetMigrate Integration**
- CliCommandFactory wraps LetMigrate commands
- But doesn't apply enterprise safeguards
- No coordination with lock manager, backup manager, approval manager

---

## GDA Architecture for Commands

```
┌────────────────────────────────────────┐
│         Command Layer                  │
│   (module:add, migrate:run, etc)       │
│   – Thin wrappers only                 │
│   – Call services, never repos         │
└────────────────────────────────────────┘
                 ↓
┌────────────────────────────────────────┐
│         Service Layer                  │
│   • ModuleManagementService             │
│   • MigrationService                    │
│   • SeedingService                      │
│   – Coordinate with enterprise features │
│   – Acquire locks, create backups       │
│   – Only layer calling both repo + gw  │
└────────────────────────────────────────┘
                 ↓
┌────────────────────────────────────────┐
│       Repository Layer                 │
│   • ModuleRepository (git operations)   │
│   • MigrationRepository (LetMigrate)    │
│   • SeederRepository (LetMigrate)       │
│   – Only access external systems        │
└────────────────────────────────────────┘
                 ↓
┌────────────────────────────────────────┐
│    Infrastructure / External           │
│   • Git (Shell)                         │
│   • LetMigrate                          │
│   • Database (via Port)                 │
└────────────────────────────────────────┘
```

---

## New Plugin Structure

```
plugins/Commands/
├── module.json
├── Provider.php
├── API/
│   ├── Contracts/
│   │   ├── ModuleManagementServiceContract.php
│   │   ├── MigrationServiceContract.php
│   │   └── SeedingServiceContract.php
│   └── DTOs/
│       ├── MigrateRequest.php
│       ├── MigrateResponse.php
│       ├── ModuleAddRequest.php
│       └── ModuleRemoveRequest.php
│
├── Domain/
│   ├── Entities/
│   │   ├── Migration.php
│   │   ├── MigrationBatch.php
│   │   └── Module.php
│   ├── ValueObjects/
│   │   ├── MigrationStatus.php
│   │   ├── MigrationDirection.php
│   │   └── ModuleName.php
│   └── Events/
│       ├── MigrationStartedDomainEvent.php
│       ├── MigrationCompletedDomainEvent.php
│       └── ModuleAddedDomainEvent.php
│
├── Application/
│   └── Services/
│       ├── ModuleManagementService.php
│       ├── MigrationService.php
│       └── SeedingService.php
│
├── Infrastructure/
│   ├── Persistence/
│   │   ├── ModuleRepository.php
│   │   ├── MigrationRepository.php (wraps LetMigrate)
│   │   └── SeederRepository.php
│   ├── Gateways/
│   │   ├── ShellGateway.php (wraps php-io-cli Shell)
│   │   └── LetMigrateGateway.php (wraps LetMigrate)
│   └── Http/
│       └── Commands/
│           ├── ModuleAddCommand.php
│           ├── ModuleRemoveCommand.php
│           ├── MigrateCliCommandFactory.php
│           └── (other thin command wrappers)
│
├── Configuration/
│   ├── ConfigurationValidator.php
│   └── EnvironmentConfigurationLoader.php
│
├── Deployment/
│   ├── DeploymentLockManager.php
│   └── DeploymentLockedException.php
│
├── Logging/
│   └── CommandExecutionLogger.php
│
├── Validation/
│   └── PreFlightValidator.php
│
├── Backup/
│   └── BackupManager.php
│
├── Approval/
│   └── MigrationApprovalManager.php
│
├── Secrets/
│   └── SecretsManager.php
│
└── Exceptions/
    └── ConfigurationException.php
```

---

## Key Design Patterns

### 1. **Command = Thin Wrapper**
```php
final class ModuleAddCommand extends AbstractCommand
{
    public function __construct(
        private readonly ModuleManagementServiceContract $service,
    ) {}

    protected function handle(): int
    {
        $request = ModuleAddRequest::fromInput($this);
        $response = $this->service->addModule($request);
        $this->renderResponse($response);
        return self::SUCCESS;
    }
}
```

### 2. **Service Layer Coordinates Everything**
```php
final class ModuleManagementService implements ModuleManagementServiceContract
{
    public function __construct(
        private readonly ModuleRepository $repository,
        private readonly ShellGateway $shell,
        private readonly CommandExecutionLogger $logger,
        private readonly DeploymentLockManager $lockManager,
    ) {}

    public function addModule(ModuleAddRequest $request): ModuleAddResponse
    {
        $this->logger->logStart('module:add', [$request->name]);
        $this->lockManager->acquireLock();
        
        try {
            $module = $this->repository->add($request);
            $this->logger->logEnd(0);
            return ModuleAddResponse::success($module);
        } catch (\Throwable $e) {
            $this->logger->logEnd(1, $e->getMessage());
            throw new ServiceException('module.add.failed', previous: $e);
        } finally {
            $this->lockManager->releaseLock();
        }
    }
}
```

### 3. **Repository Wraps External Systems**
```php
final class MigrationRepository
{
    public function __construct(
        private readonly LetMigrateGateway $letMigrate,
        private readonly DatabasePort $db,
    ) {}

    public function runPending(array $config): array
    {
        $commands = CliCommandFactory::fromConfig($config);
        return $commands->migrate()->run();
    }
}
```

### 4. **DTOs for Communication**
```php
final readonly class MigrateRequest
{
    public function __construct(
        public readonly ?string $config,
        public readonly bool $pretend,
        public readonly int $steps,
    ) {}
    
    public static function fromInput(AbstractCommand $command): self
    {
        return new self(
            config: $command->option('config'),
            pretend: $command->hasOption('pretend'),
            steps: (int) $command->option('steps', '0'),
        );
    }
}
```

---

## Enterprise Integration Points

### Before Migration
```
Command
  ↓ (direct git)
Shell
  ↓
Git
```

### After Refactoring (GDA-compliant)
```
Command
  ↓
ModuleManagementService
  ├→ DeploymentLockManager (acquire lock)
  ├→ PreFlightValidator (check system)
  ├→ CommandExecutionLogger (log start)
  ├→ ModuleRepository
  │   └→ ShellGateway (git operations)
  ├→ BackupManager (create backup)
  ├→ MigrationApprovalManager (check approval)
  └→ EventBus (emit domain events)
```

---

## Implementation Phases

### Phase 1: Service Layer (Day 1)
- [x] Create ModuleManagementServiceContract
- [x] Create MigrationServiceContract
- [ ] Create ModuleManagementService
- [ ] Create MigrationService
- [ ] Create SeedingService
- [ ] Create DTOs (Request/Response)

### Phase 2: Repository Layer (Day 2)
- [ ] Create ModuleRepository
- [ ] Create MigrationRepository
- [ ] Create SeederRepository
- [ ] Create ShellGateway
- [ ] Create LetMigrateGateway

### Phase 3: Command Wrappers (Day 3)
- [ ] Move ModuleAddCommand → thin wrapper
- [ ] Move ModuleRemoveCommand → thin wrapper
- [ ] Create MigrateCliCommandFactory wrapper
- [ ] Move/refactor all other commands

### Phase 4: Integration (Day 4)
- [ ] Wire Provider.php to use new services
- [ ] Add tests for services
- [ ] Verify enterprise features work
- [ ] Update documentation

---

## Current vs. Proposed

| Aspect | Current | Proposed |
|--------|---------|----------|
| Command structure | Command → Shell | Command → Service → Repository → Gateway |
| Configuration | Pre-loaded in factory | Passed through service layer |
| Locks | None | DeploymentLockManager |
| Backups | None | BackupManager in service |
| Approval | None | MigrationApprovalManager |
| Logging | None | CommandExecutionLogger |
| Error handling | Direct | Translated to ServiceException |
| Testing | Hard (Shell deps) | Easy (mock services) |
| Reusability | Commands only | Services + commands |

---

## Benefits

✅ **Separation of Concerns** — Each layer has one responsibility  
✅ **Enterprise Features** — Locks, backups, approval built-in  
✅ **Testability** — Easy to mock services  
✅ **Reusability** — Services can be called from HTTP handlers too  
✅ **GDA Compliance** — Follows framework patterns exactly  
✅ **Error Handling** — Proper exception translation  
✅ **Auditability** — All operations logged  

---

## Implementation Order

1. Create service contracts + DTOs
2. Create domain entities + value objects
3. Create services (coordinate with repos/gateways)
4. Create repositories (talk to external systems)
5. Create gateways (wrap vendors)
6. Refactor commands to use services
7. Wire in Provider.php
8. Add tests
9. Update documentation
