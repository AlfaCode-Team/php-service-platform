# What Each File Contains — Full Content Index

Use this to know exactly which file to open for any topic.

---

## 00_AI_SYSTEM_PROMPT.md
**USE: Paste into every Claude session as the first message**

Contains:
- Framework identity statement (not Laravel, not Symfony)
- The Five Access Rules written for AI consumption
- Layer definitions in compact form
- The mandatory transaction + event pattern
- Two-column event type comparison
- Exception rules by layer
- 14 things never to do
- Testing pattern summary
- Pre-output checklist (AI checks this before generating code)

---

## 00_SENTINEL_OVERVIEW.md
**USE: Always register — foundational architecture**

Contains:
- The Six Core Principles (with what each means)
- The Three Worlds diagram (Kernel → Module → Project)
- The complete HTTP request lifecycle (stage by stage)
- Module directory structure (every folder explained)
- Complete annotated module.json schema (every field)
- Exception hierarchy with HTTP status codes
- All kernel port interface names
- Index linking to all other context files

---

## 01_KERNEL.md
**USE: When working on kernel code, boot sequence, containers, or security layers**

Contains:
- Eight kernel responsibilities (component + pattern)
- What the kernel does NOT own (explicit list)
- BootPipeline stage order (all 8 stages)
- SecurityGateway and how verdicts work
- Identity value object (complete code)
- OnDemandLoader flow diagram
- **Container Architecture — bind-it engine** (new section)
  - bind-it as base class for CoreContainer and ModuleContainer
  - CoreContainer API: `instance()`, `freeze()`, `isFrozen()`, disabled singletons
  - ModuleContainer API: `bindInternal()`, `makeInScope()`, `reset()`, disabled singletons
  - Swoole/FPM safety rules and when to call `reset()`
- CoreContainer vs ModuleContainer comparison table (extended)
- ModuleContainer scope enforcement
- ErrorPipeline (four stages + notifier chain)
- All five port interfaces (complete signatures)
- AI instructions for kernel code

---

## 02_MODULE.md
**USE: When creating a new module or editing module.json / Provider.php**

Contains:
- Module identity rules (6 rules)
- Fully annotated module.json schema (every field with comment)
- ModuleContract interface (complete with docblocks)
- Canonical Provider.php implementation (complete code)
- Pipeline hook slots and priority conventions
- Cross-module communication (Option 1: contract, Option 2: event)
- Module type variants (module, job, command)
- AI instructions for module code

---

## 03_DOMAIN.md
**USE: When writing Entities, Value Objects, Domain Events, Rules, or Enums**

Contains:
- Domain layer rules (7 absolute rules)
- Complete Entity pattern (Invoice with all regions annotated)
  - Private constructor
  - Named static constructors (create + reconstitute)
  - State transitions with ensureStatus()
  - Domain event recording and releasing
  - Read-only getters
- Complete Value Object pattern (Money with all operations)
  - Integer cents storage
  - Named constructors (of, fromCents, zero)
  - Immutable operations (add, subtract, multiply)
  - Equality and comparison
- Domain Event pattern (past-tense, final readonly)
- Domain Event flow (how events travel from entity to service)
- Domain Rule pattern (static check methods)
- Status Enum pattern (backed string enum with state machine)
- Domain layer file checklist
- AI instructions for domain code

---

## 04_SERVICE.md
**USE: When writing Application Services**

Contains:
- Service layer rules (9 absolute rules)
- Complete canonical service implementation (InvoiceService)
  - Constructor with all dependencies
  - Authorization check pattern
  - Transaction + collector + commit + dispatch pattern
  - Exception wrapping
- Transaction pattern (the exact required shape)
- Why dispatch-inside-try is wrong (explained)
- Input DTO pattern (with fromRequest() validation)
- Output DTO pattern (with from(Entity) factory)
- Service contract pattern (with @throws docblocks)
- Service security pattern (RBAC + ABAC combined)
- AI instructions for service code

---

## 05_REPOSITORY.md
**USE: When writing Repositories, Hydrators, or Migrations**

Contains:
- Repository rules (8 absolute rules)
- Complete canonical repository (InvoiceRepository)
  - find() with tenant scoping, soft delete, \PDOException translation
  - save() with upsert pattern
  - softDelete()
  - findByCriteria() with buildWhere() helper
  - sanitizeOrder() against allowlist
- Complete Hydrator pattern (hydrate + dehydrate)
- Migration pattern (complete with all standard columns and indexes)
- AI instructions for repository code

---

## 06_GATEWAY.md
**USE: When writing Gateways (third-party API wrappers)**

Contains:
- Gateway rules (7 absolute rules)
- Complete canonical gateway (StripePaymentGateway)
  - Every Stripe exception type caught explicitly
  - Translation to GatewayException with layer context
- Gateway contract pattern
- ChargeResult value object (success, requiresAction, failed)
- Circuit breaker integration pattern
- Gateway naming conventions
- Fake gateway for testing (with failOnNextCall, wasCalledFor)
- AI instructions for gateway code

---

## 07_CONTROLLER.md
**USE: When writing HTTP Controllers**

Contains:
- Controller rules (5 absolute rules)
- Canonical controller (InvoiceController with all 5 CRUD methods)
- Response factory method reference (all methods)
- Standard response shapes:
  - Success (200/201)
  - Paginated list (200)
  - Validation error (422 with fields)
  - Service/Auth error (4xx/5xx)
- Request contract (all methods)
- DTO validation in fromRequest() (complete example)
- File upload in controller pattern
- AI instructions for controller code

---

## 08_EVENTS.md
**USE: When working with Domain Events, Integration Events, or Projections**

Contains:
- Two event types comparison table (9 aspects)
- Domain Event pattern (final readonly, past tense, minimum data)
- Domain Event flow (entity → collector → projection → commit)
- Integration Event pattern (versioned, primitive types, after commit)
- Integration Event dispatch (AFTER commit — complete code)
- Subscribing to events (module.json + Provider.boot() + Listener class)
- Event versioning (adding fields without breaking v1 subscribers)
- Projection pattern (updating read models from domain events)
- AI instructions for event code

---

## 09_SECURITY.md
**USE: When working on SecurityGateway, Identity, JWT, or rate limiting**

Contains:
- Security architecture diagram (gate → layers → pipeline)
- SecurityLayerContract interface
- SecurityVerdict (allow, deny, methods)
- Identity value object (final readonly, hasRole, hasPermission, isGuest)
- Built-in security layers (Firewall, RateLimiter, TokenValidator)
- Writing a custom security layer (complete example)
- JWT token lifecycle (login → validate → refresh)
- Rate limit configuration (config/security.php complete example)
- Service-level authorization pattern (RBAC + ABAC combined)
- AI instructions for security code

---

## 10_TESTING.md
**USE: When writing tests**

Contains:
- Test directory structure (Unit/Domain, Unit/Application, Integration/, Fixtures/)
- Test double taxonomy table (Stub, Fake, Spy, Mock, Dummy — when to use each)
- Domain unit tests (pure PHP, no fakes, sub-millisecond)
  - Entity lifecycle tests
  - Business rule violation tests
  - Domain event tests
- Service integration tests with fakes
  - Happy path
  - Transaction committed
  - Integration event dispatched
  - Rollback and event discard
  - Unauthorized access
- Port fakes (complete implementations):
  - InMemoryInvoiceRepository (with failOnNextSave, count, all)
  - FakeIntegrationEventBus (with dispatched, assertDispatched, assertNotDispatched)
  - FakeTransactionManager (with wasCommitted, wasRolledBack, wrap)
- Repository integration test pattern (transactional rollback)
- AI instructions for test code

---

## 11_PROJECT.md
**USE: When working on bootstrap, port adapters, or configuration**

Contains:
- Project layer rules (7 rules)
- Complete project directory structure
- bootstrap/app.php complete wiring (ALL sections with comments)
- MySQLAdapter complete implementation (DatabasePort)
- Environment configuration pattern (config/ + .env)
- Three entry points (public/index.php, cli.php, worker.php)
- AI instructions for project layer code

---

## 12_WORKER.md
**USE: When writing background jobs**

Contains:
- Worker pipeline stages (full diagram)
- JobContract interface
- JobPayload contract (all methods)
- Canonical job implementation (SendInvoiceEmailJob)
  - Using published contract
  - Returning JobResult::skipped() vs throwing
  - Implementing failed()
- Job module.json (complete with queue, retry, timeout)
- Job dispatch from service (after commit)
- Retry strategies (exponential backoff with jitter, delay calculation)
- Bulk/long-running job pattern (batching + progress + graceful shutdown)
- JobResult (success, skipped)
- Queue configuration (config/jobs.php)
- Dead-letter queue management (CLI commands)
- Supervisor configuration (two worker pools)
- AI instructions for worker/job code

---

## 13_ANTIPATTERNS.md
**USE: Every session — prevents wrong suggestions**

Contains 15 complete wrong/correct code pairs:
1. Cross-module repository access → use contract interface
2. Business logic in controller → delegate to service
3. Event dispatch before commit → dispatch after commit
4. Domain with external dependencies → pure PHP domain
5. Shared database tables between modules → contract-based access
6. Routes in PHP files → routes in module.json
7. Skipping a job by throwing → return JobResult::skipped()
8. Static properties for state → use CachePort
9. Authorization in SecurityGateway → authorization in service
10. Missing declare(strict_types=1) → add to every file
11. Float for money → use Money value object with cents
12. Undeclared config vars → declare in module.json config[]
13. **Global container singletons → inject via DI (getInstance() is disabled)**
14. **Binding services after Kernel::build() → frozen CoreContainer throws LogicException**
15. **Resolving internal bindings from wrong scope → use makeInScope() or published contract**
- Extended "never do → do this instead" matrix including CLI and container rules

---

## 14_CLI.md
**USE: When writing CLI command modules**

Contains:
- **CLI pipeline engine** — `CliPipeline` wraps `CLIApplication` from php-io-cli package
- **`AbstractCommand`** — standalone base class (zero Symfony dependency)
  - `configure()` — declare `$name`, `$description`, `addArgument()`, `addOption()`
  - `handle(): int` — read via `$this->argument()` / `$this->option()` / `$this->hasOption()`
  - Output via `$this->info()`, `$this->success()`, `$this->warning()`, `$this->error()`, `$this->muted()`
  - Registration: `$cli->command(MyCommand::class)` — class-string only
- CLI pipeline stages (full diagram)
- Canonical command implementation (GenerateMonthlyInvoicesCommand)
- Argument/option declaration patterns
- Component factory shortcuts (`ask`, `select`, `confirm`, `progressBar`, `spinner`, `table`)
- Command module.json (complete)
- Seeder command pattern
- Protected commands (require operator authentication)
- AI instructions for CLI command code (corrected — no Symfony types)

---

## 17_PHP_IO_CLI.md
**USE: When working with php-io-cli components, I/O layer, Shell, or Colors directly**

Contains:
- Full architecture diagram (CLIApplication → AbstractCommand → IOInterface → Components)
- **AbstractCommand** complete reference (configure + handle + all input/output methods)
- **CLIApplication** — bootstrap, built-in commands, global flags, not-found suggestions, Composer discovery
- **All 14 components** with usage examples and key bindings:
  - Interactive: TextInput · Password · NumberInput · Confirm · Select · MultiSelect · Autocomplete · DatePicker
  - Interactive (undocumented in module README): RadioGroup · SliderInput
  - Display: Table · Alert · ProgressBar · SpinnerComponent
- **Shell** — `Shell::run()` + `Shell::capture()` + full `ShellResult` API
- **Shell + ProgressBar** canonical pattern for animated steps
- **I/O Layer** — ConsoleIO (TTY + Symfony fallback), BufferIO (tests), NullIO (daemons)
- **Colors** utility — full API including hex, strip, enable/disable
- **Internals** — AbstractPrompt lifecycle, State, Input bindings, Hooks event bus
- **Testing commands** with BufferIO (full examples)
- **Component inventory table** — all 20 public classes
- What MUST NOT happen with php-io-cli

---

## 18_MIGRATIONS.md
**USE: When working with database migrations, schema changes, or seeders**

Contains:
- **LetMigrate** overview — enterprise-grade, framework-agnostic migration engine
- Bootstrap & configuration (single DB, multi-DB, all config options)
- Writing migrations (file naming, templates, `make:migration` scaffolder)
- **Blueprint API reference**:
  - All 15+ column types (numeric, string, datetime, special)
  - Column modifiers (chainable: nullable, default, unique, etc.)
  - Timestamps behavior (MySQL inline vs PostgreSQL triggers)
  - Indexes & constraints (simple, composite, foreign keys)
  - Table operations (create, alter, modify, rename, drop)
  - Schema introspection (getTables, getColumns, getIndexes, getForeignKeys)
- Runner operations (run, rollback, reset, refresh, status, pending)
- Events & lifecycle hooks (MigrationStarted, Finished, Failed, Completed)
- Seeder engine (writing seeders, CLI commands, dependency ordering, SeederRunner API)
- Pretend mode (CI previews)
- Custom drivers & grammars (extend for additional databases)
- Database-specific notes (MySQL, PostgreSQL, SQLite, SQL Server quirks)
- Error handling (exception types)
- Complete workflow example
- What CLAUDE MUST NEVER DO (15+ migration antipatterns)
- Full CLI commands reference (migrate:* + seed:* commands)

---

## 15_ERROR_HANDLING.md
**USE: When implementing error handling or debugging exceptions**

Contains:
- Exception hierarchy and HTTP status mapping
- How to throw the right exception per layer (code examples)
- FrameworkException constructor (all parameters)
- ErrorContext auto-captured fields (complete table)
- Error severity rules (config/errors.php)
- HTTP error response format (standard JSON envelope)
- All standard error codes (dot-notation reference)
- Exception translation chain (PDOException → RepositoryException → HTTP 500)
- What NOT to do with errors (swallowing, generic catching)
- AI instructions for error handling code

---

## CLAUDE_AI_GUIDE.md
**USE: Read this to understand how to use all files in Claude.ai**

Contains:
- Option A: Claude Projects setup (step-by-step with UI navigation)
  - Which files to upload
  - How to add your own code as knowledge
  - How to start conversations
- Option B: Single conversation (paste context manually)
  - What to paste first
  - Which layer file to paste per task
  - Example first messages
- 8 copy-paste prompt templates for:
  1. Complete new module
  2. Code review
  3. Domain entity
  4. Service with transactions
  5. Repository with complex queries
  6. Tests
  7. Debugging errors
  8. Background job
- Tips for best results (DOs and DON'Ts)
- Context window management
- Recommended 6-conversation workflow for a complete module

---

## PROMPT_TEMPLATES.md
**USE: Copy these into Claude for every AlfacodeTeam PhpServicePlatform coding task**

Contains:
- Session starter (paste before every conversation)
- 12 ready-to-copy prompt templates:
  1. New module (complete scaffold)
  2. Add method to existing service
  3. Value object
  4. State machine entity
  5. Repository with specific queries
  6. Third-party API gateway
  7. Code review for violations
  8. Complete test suite
  9. Debug an exception
  10. Generate module.json from existing code
  11. Explain a AlfacodeTeam PhpServicePlatform design decision
  12. Port Laravel/Symfony code to AlfacodeTeam PhpServicePlatform
- Quick one-liner prompts for small tasks

---

## README.md
**USE: Start here — how to use all files**

Contains:
- Complete file list with description
- How to register with Claude.ai (Projects + single session)
- How to register with Cursor (.cursorrules)
- How to register with GitHub Copilot
- How to register with ChatGPT
- Recommended context combinations by task (table)
- Quick reference card
