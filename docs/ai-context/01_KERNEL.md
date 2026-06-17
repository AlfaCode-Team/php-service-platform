# AlfacodeTeam PhpServicePlatform — Kernel Layer Context

> The kernel is the **smallest, most stable** component. It owns exactly eight responsibilities
> and knows nothing about any module or business domain. When you see code in `vendor/sentinel/kernel/`,
> these rules describe it.

---

## What the Kernel Owns

| Responsibility | Component | Pattern |
|---|---|---|
| Boot orchestration | `BootPipeline` + stages | Pipeline / Fail-Fast |
| HTTP request processing | `HttpPipeline` + stages | Chain of Responsibility |
| Pre-bootstrap security | `SecurityGateway` | Chain of Responsibility |
| Module loading | `OnDemandLoader` + `DependencyGraphCalculator` | Strategy + Template Method |
| Error handling | `ErrorPipeline` + `ErrorConsumer` | Observer + Chain of Responsibility |
| Cross-module messaging | `EventBus` + `DomainEventCollector` | Observer / Mediator |
| Dependency injection | `CoreContainer` + `ModuleContainer` | IoC Container |
| Infrastructure abstraction | Port interfaces | Ports and Adapters |

## What the Kernel Does NOT Own

The kernel has **zero knowledge** of:
- Which modules exist
- What any module does
- Any business domain (invoices, payments, users, etc.)
- Any infrastructure implementation (MySQL, Redis, S3, etc.)
- Any HTTP framework (Laravel, Symfony, Slim, etc.)

---

## BootPipeline — Runs Once at Startup

```php
// Stages run in this exact order. Any failure = immediate shutdown.
ValidateConfigStage::class,         // 1. All env vars present and correct type
DetectConflictsStage::class,        // 2. No two modules share the same solves() domain
DetectCyclesStage::class,           // 3. No circular dependency chains in requires[]
CompileRouteManifestStage::class,   // 4. routes[] → projects/<name>/var/cache/manifests/route-manifest.php
CompileJobManifestStage::class,     // 5. type:job → projects/<name>/var/cache/manifests/job-manifest.php
CompileCommandManifestStage::class, // 6. type:command → projects/<name>/var/cache/manifests/command-manifest.php
RegisterPortsStage::class,          // 7. Port interface → Adapter bindings
BindSecurityStage::class,           // 8. SecurityGateway + layers initialized
```

**Rule:** Boot fails loudly with a descriptive `BootException` listing exactly what is wrong.
It never starts with missing config, conflicting modules, or circular dependencies.

---

## Kernel Lifecycle — build() vs materialize()

Startup is **two phases**. `build()` is compile-only; the heavy work is deferred to the
first entry-point call so a process only pays for the surface it actually uses.

| Phase | Trigger | Work done |
|---|---|---|
| `build()` | explicit, once | Set paths, bind ports into `CoreContainer`, run `BootPipeline` (validate config + compile manifests). **No pipelines, no module wiring, no freeze.** |
| `materialize(RuntimeMode)` | first `http()` / `cli()` / `workerLoop()` / `container()` call, once | Construct pipelines, wire every module ONCE (`Provider::boot`), bind kernel services, **freeze the core container**. |

```php
$kernel->http()        // → materialize(RuntimeMode::Http),   then HttpPipeline
$kernel->cli()         // → materialize(RuntimeMode::Cli),    then CliPipeline
$kernel->workerLoop()  // → materialize(RuntimeMode::Worker), then WorkerLoop
$kernel->container()   // → materialize(RuntimeMode::Cli),    then frozen CoreContainer
$kernel->mode()        // → ?RuntimeMode the kernel materialized for (null before first call)
```

**Why all three pipelines still get constructed at materialize:** `ModuleContract::boot()`
registers HTTP hooks, CLI commands, worker hooks **and** event subscriptions in a single
call, so all three pipeline instances must exist when modules wire. They are cheap shells —
`HttpPipeline` and `WorkerLoop` defer their manifest disk I/O until their OWN first run
(`handle()` / first job). Net effect: an HTTP-only process never reads the job manifest, and
a CLI process never reads the route manifest.

**Rule:** Treat `materialize()` as private — never call it directly. Reach an entry point and
the kernel materializes itself for that surface.

---

## SecurityGateway — Permanent Resident

```php
interface SecurityLayerContract
{
    public function check(Request $request): SecurityVerdict;
}

final class SecurityVerdict
{
    public static function allow(Request $request): self;   // Identity attached
    public static function deny(int $code, string $reason): self; // 401/403/429
    public function isDenied(): bool;
    public function identity(): ?Identity;
}
```

**Critical rules:**
- Runs **before** any module loads. Denied requests never touch module code.
- Each layer returns `allow` or `deny`. The first `deny` short-circuits all remaining layers.
- Order matters: Firewall (cheapest) → RateLimiter → TokenValidator (most expensive).
- `Identity` is immutable. Once set by the gateway it cannot be modified downstream.

---

## Identity — Immutable Value Object

```php
final readonly class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly array  $roles,
        public readonly array  $permissions,
        public readonly string $tokenType, // 'jwt' | 'api_key' | 'session'
    ) {}

    public function hasRole(string $role): bool;
    public function hasPermission(string $perm): bool;
    public function isGuest(): bool;
}
```

**Rule:** `Identity` flows through the entire request from SecurityGateway to Service.
Services receive it via constructor injection from the scoped container.

---

## OnDemandLoader — Per-Request Module Loading

```
Request cleared by SecurityGateway
    │
    ▼
RouteManifest::match(method, path)  → service name (e.g. 'invoice.generation')
    │
    ▼
DependencyGraphCalculator::resolve(service)
    → [database.query, pdf.generation, invoice.generation]  (ordered, minimal)
    │
    ▼
OnDemandLoader::load(graph, request)
    → Instantiate each module: register() then boot()
    → Return request-scoped ModuleContainer
    │
    ▼
Request executes → Container discarded at end of request
```

**Rule:** The dep graph is **pre-compiled** into `service-manifest.php` at deploy time.
The calculator reads a PHP array — zero I/O, microsecond lookup.

---

## Container Architecture — bind-it Engine

Both `CoreContainer` and `ModuleContainer` extend `PHPShots\Common\Container` from the **bind-it**
package (`phpshots/bind-it`, lives in `modules/bind-it/`). This provides reflection-based
autowiring, PSR-11 compliance, contextual bindings, extenders, `resolving()` / `rebinding()`
callbacks. GDA scope rules are layered on top inside the kernel wrappers.

---

## CoreContainer — App-Lifetime

One instance per worker process. Created by `Kernel::configure()`, frozen during
`materialize()` — the lazy step that runs on the first entry-point call, NOT in `build()`.

```php
$core->instance(DatabasePort::class, $adapter);    // register port implementation
$core->singleton(TransactionManager::class, ...);  // kernel services
$core->freeze();        // called AUTOMATICALLY during materialize() — no writes after this
$core->isFrozen(): bool // false after build(), true after materialize()

// DISABLED — throw LogicException (no global singleton in Swoole workers):
$core->getInstance(); // ← LogicException
$core->setInstance(); // ← LogicException
```

**Rule:** Never call `bind()`, `singleton()`, or `extend()` on `CoreContainer` after the
kernel materializes (the first `http()`/`cli()`/`workerLoop()`/`container()` call). The
container is frozen and will throw `LogicException`.

---

## ModuleContainer — Request-Scoped

New instance per request, discarded at end of request. Scope isolation enforced at runtime.

```php
// Internal binding — ScopeViolationException if resolved from outside this module
$container->bindInternal(InvoiceRepository::class, fn($c) =>
    new InvoiceRepository($c->make(DatabasePort::class))
);

// Public binding — resolvable by modules that declare this in requires[]
$container->bind(InvoiceServiceContract::class, fn($c) =>
    new InvoiceService($c->make(InvoiceRepository::class), ...)
);

// Resolve with explicit caller scope — used by ExecuteStage for controllers
$container->makeInScope(InvoiceController::class, 'invoice.generation');

// Full lifecycle teardown — MUST be called at end of every Swoole request
$container->reset();

// DISABLED — throw LogicException:
$container->getInstance(); // ← LogicException
$container->setInstance(); // ← LogicException
```

**Rule:** In Swoole workers, call `$container->reset()` at end of each request.
`OnDemandLoader` creates a fresh `ModuleContainer` per request — reset is automatic in HTTP.
For Swoole coroutines, wire `Kernel::requestTeardown()` into your coroutine cleanup hook.

---

## CoreContainer vs ModuleContainer

| Feature | CoreContainer | ModuleContainer |
|---|---|---|
| Lifetime | Application lifetime (one per process) | Request lifetime (new per request) |
| Scope | All modules + kernel services | One module only |
| Contains | Port implementations, kernel services | Module services, repositories, gateways |
| Internal access | Any code | Only the owning module |
| Isolation | None | Full — ScopeViolationException on cross-access |
| Write lock | Frozen during `materialize()` (first entry-point call) | No lock — discarded after request |
| Teardown | None (process lifetime) | `reset()` — wipes all state |
| Global singleton | Disabled (`getInstance()` throws) | Disabled (`getInstance()` throws) |

---

## ModuleContainer — Scope Isolation

```php
// ENFORCED AT RUNTIME — not just a convention
$container->bindInternal(InvoiceRepository::class, ...);
// ↑ Bindings marked internal throw ScopeViolationException if resolved
//   from any scope other than the module that owns them.

// Cross-module access: only through published contracts
$container->bind(InvoiceServiceContract::class, ...);
// ↑ Resolvable from any scope — this is the module's public API
```

**Rule:** `ScopeViolationException` is thrown if Module A resolves Module B's internal binding.
Fix: use Module B's published contract from `API/Contracts/`.

---

## ErrorPipeline

```
Any Throwable
    │
    ▼
ErrorInterceptor      ← catches, wraps in ErrorContext
    │
    ▼
ErrorNormalizer       ← adds request metadata, correlation ID, severity
    │
    ▼
ErrorClassifier       ← assigns: critical | warning | info
    │
    ▼
ErrorDispatcher       ← routes to notifier chain
    ├── SlackNotifier (critical)
    ├── MailNotifier  (critical)
    ├── DatabaseLogger (warning+)
    └── FileNotifier  (always — fallback)
```

**Rule:** `FileNotifier` always runs. It is the guaranteed fallback even if all others fail.
Notifier failures are silently ignored so they never mask the original error.

---

## Port Interfaces (Kernel-Defined)

The kernel defines these interfaces. The project provides implementations.
**No module imports an implementation — only the interface.**

```php
interface DatabasePort {
    public function query(string $sql, array $params = []): array;
    public function queryOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
}

interface CachePort {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
}

interface QueuePort {
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string;
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string;
}

interface MailPort {
    public function send(string|array $to, string $subject, string $view, array $data = []): void;
    public function queue(string|array $to, string $subject, string $view, array $data = []): string;
}

interface StoragePort {
    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string;
    public function get(string $path): string;
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;
    public function delete(string $path): bool;
}
```

---

## AI Instructions for Kernel Code

When generating or reviewing kernel code:

- **DO** keep kernel classes free of any business domain knowledge
- **DO** use port interfaces — never concrete adapters
- **DO** throw `KernelException` for kernel-level failures
- **DO** ensure BootPipeline stages fail fast with descriptive messages
- **DON'T** add module-specific logic to kernel classes
- **DON'T** let `\PDOException`, `\RedisException`, or any vendor exception escape the kernel
- **DON'T** add new port methods without backward compatibility consideration
- **DON'T** modify `Identity` after it is set by the SecurityGateway
