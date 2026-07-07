# AlfacodeTeam PhpServicePlatform Framework — GitHub Copilot Instructions
# This file is ONLY about the AlfacodeTeam PhpServicePlatform Framework (Gated Demand Architecture).
# Place at: .github/copilot-instructions.md
# Copilot reads this automatically for every suggestion and chat request.

---

## WHAT THIS PROJECT IS

This is the **AlfacodeTeam PhpServicePlatform Framework** — a PHP 8.2+ framework built on the
**Gated Demand Architecture (GDA)** pattern.

Namespace root: `AlfacodeTeam\PhpServicePlatform\Kernel\`
Package name:   `alfacode-team/php-service-platform`

This is NOT a Laravel, Symfony, or Slim application.
Do NOT suggest those frameworks' patterns, classes, or conventions.

---

## NATIVE DISTRIBUTION NOTE

This repository supports native OS distribution (no Composer required for end users).

- Release workflow: `.github/workflows/release.yml`
- Trigger: push a `v*` tag (example: `v1.0.0`)
- Artifacts:
    - Linux `.deb`
    - Windows `.zip` bundle (contains `psp.exe`)
    - macOS universal `.app` tarball

When documenting install paths, prefer `packaging/README.md` and native artifacts first.
Composer global install is optional and primarily for development workflows.

---

## THE THREE WORLDS

```
PROJECT LAYER   (wiring only — no business logic)
└── MODULE LAYER  (bounded domain contexts)
    └── KERNEL    (boot, security, loading, pipelines, DI, events, ports)
```

The Kernel knows NOTHING about modules or business domains.
Modules know nothing about the project.
The project wires them together using a shared base builder (`app/bootstrap/base.php`) and per-project bootstraps (`projects/{project}/bootstrap/app.php`).

---

## THE FIVE ACCESS RULES (ABSOLUTE — ENFORCED AT RUNTIME)

```
Controller  →  Service   (published contract interface only)
Service     →  Repository  AND  Gateway
Repository  →  DatabasePort only
Gateway     →  Vendor SDK only
Domain      →  Nothing external (zero imports outside Domain/)
```

`ModuleContainer::bindInternal()` enforces these at runtime.
Violations throw `ScopeViolationException`.

---

## KERNEL FOLDER STRUCTURE

```
src/
├── Kernel.php                     ← fluent builder: configure()->withPorts()->withModules()->build()
├── Contracts/ModuleContract.php   ← solves(), requires(), exposes(), register(), boot()
│
├── Boot/
│   ├── BootPipeline.php           ← 10 stages, fail-fast on any error
│   └── Stages/
│       ├── ValidateConfigStage    ← env vars present + typed
│       ├── DetectConflictsStage   ← no two modules share same solves()
│       ├── DetectCyclesStage      ← no circular requires[] chains
│       ├── CompileServiceManifest ← services[] → service-manifest.php (+ __project__ scope)
│       ├── CompileRouteManifest   ← routes[] → route-manifest.php (plugin + project; project OVERRIDES)
│       ├── CompileViewManifest    ← views[] → view-manifest.php (project-first cascade + namespaces)
│       ├── CompileJobManifest     ← job modules → job-manifest.php
│       ├── CompileCommandManifest ← command modules → command-manifest.php
│       ├── RegisterPortsStage     ← verify port bindings exist
│       └── BindSecurityStage      ← verify SecurityGateway configured
│
├── Security/
│   ├── SecurityGateway.php        ← runs before ANY module loads
│   ├── SecurityVerdict.php        ← allow(Identity) | deny(code, reason)
│   ├── Identity.php               ← immutable: userId, tenantId, roles, permissions
│   └── Contracts/SecurityLayerContract.php
│
├── Loading/
│   ├── DependencyGraphCalculator.php  ← DFS over service-manifest.php
│   ├── DependencyGraph.php            ← ordered module list
│   └── OnDemandLoader.php             ← register() then boot() per module
│
├── Container/
│   ├── CoreContainer.php          ← app-lifetime: ports + kernel services
│   └── ModuleContainer.php        ← request-scoped: scope isolation enforced
│
├── Pipelines/
│   ├── Http/   HttpPipeline + 6 stages + hook system (after.security/load/execute) + FilterRegistry/RouteFilterStage (declarative route filters[])
│   ├── Cli/    CliPipeline + 6 stages  (Arguments + Output are @deprecated — use AbstractCommand)
│   └── Worker/ WorkerPipeline + WorkerLoop + JobPayload + JobResult + retry strategies
│
├── Events/
│   ├── DomainEventCollector.php   ← buffer during tx, discard on rollback
│   └── EventBus.php               ← dispatch to subscribers, isolated failures
│
├── Ports/                         ← interfaces only, project provides implementations
│   DatabasePort, CachePort, QueuePort, MailPort, SmsPort, StoragePort,
│   HttpClientPort, SessionPort, EncryptionPort, HashingPort
│
├── Http/     Request, Response, UploadedFile, Uri, SiteUri, Negotiate, Method, UserAgent,
│             Concerns/ManagesResponse   ← engine: symfony/http-foundation (transitional)
│             (outbound HTTP → HttpClientPort/plugins/HttpClient; CORS → plugins/SecurityFilters)
├── Database/ TransactionManager
├── Error/    ErrorPipeline, ErrorContext, Notifiers (Slack, Mail, DB, File)
│
└── Exceptions/
    FrameworkException (abstract base)
    ├── SecurityException    → HTTP 401/403   severity: warning
    ├── DomainException      → HTTP 422       severity: info
    ├── ServiceException     → HTTP 422/500   severity: warning
    ├── RepositoryException  → HTTP 500       severity: critical
    ├── GatewayException     → HTTP 502       severity: critical
    ├── KernelException      → HTTP 500       severity: critical
    ├── ScopeViolationException
    ├── CircularDependencyException
    ├── ValidationException  (carries field-level errors map)
    └── BootFailureException
```

---

## INTERNAL PACKAGES

The kernel uses four first-party packages that live in `modules/` and are autoloaded as
path repositories via `composer.json`. Do not suggest replacing them with Laravel/Symfony equivalents.

| Package | Namespace | Role |
|---|---|---|
| `phpshots/bind-it` | `PHPShots\Common\` | DI container engine — reflection autowiring, PSR-11, contextual bindings |
| `phpshots/common-type-alias` | `PHPShots\Common\TypeAlias\` | Type alias management — dependency of bind-it |
| `alfacode-team/php-io-cli` | `AlfacodeTeam\PhpIoCli\` | Standalone CLI runtime — reactive terminal components, structured command execution, unified I/O layer |
| `alfacode-team/let-migrate` | `AlfaCode\LetMigrate\` | Enterprise-grade database migration engine — multi-database, fluent Schema API, seeders, full CLI |

**bind-it** (`modules/bind-it/`) — `Container` is the base class for both `CoreContainer` and
`ModuleContainer`. It provides `bind()`, `singleton()`, `instance()`, `make()`, contextual
bindings, extenders, `resolving()` callbacks, and `rebinding()`. GDA scope rules are layered on top
inside the kernel wrappers — bind-it itself is not modified.

**php-io-cli** (`modules/php-io-cli/`) — `CliPipeline` wraps `CLIApplication`. Module commands
extend `AbstractCommand` — a **standalone** class with zero Symfony dependency. Symfony Console
is an optional dev dependency used only by `ConsoleIO`/`BufferIO` as a non-TTY fallback.
Register with `$cli->command(MyCommand::class)` — a class-string; `CliPipeline` instantiates via
`CoreContainer` (allowing port injection) or directly.

**let-migrate** (`modules/let-migrate/`) — Enterprise-grade, framework-agnostic database migration
engine. Supports MySQL, PostgreSQL, SQLite, and SQL Server with fluent `Blueprint` API. Write
migrations once, compile to correct DDL per database. Includes seeder engine with dependency
ordering, schema introspection, full CLI commands (migrate:run, make:migration, db:seed,
migrate:diff, migrate:check), and extensible driver/grammar registry. NEVER use Laravel
migrations, Doctrine migrations, or Symfony migrations — ONLY use LetMigrate in this project.

Migration Schema API notes (avoid the common mistakes that broke the bundled migrations):
- There is NO fluent `->index()` or `->useCurrent()` column modifier. Use the Blueprint
  method `$t->index(['col'])` and `->default('CURRENT_TIMESTAMP')` respectively.
- A column `->primary()` (single PK) and the Blueprint `$t->primary([...])` (composite PK)
  are mutually exclusive on one table — using both yields a duplicate PRIMARY KEY error.
- Seeder files may EITHER `return new class implements SeederInterface {}` OR declare a
  named `final class <FileName> implements SeederInterface {}` (the `make:seeder` scaffold).
- Seeding runs via `db:seed` (optionally `db:seed --class <Name>`); there is no `seed:*` group.
- Table-level options are Blueprint methods: `$t->engine('InnoDB')`, `$t->charset('utf8mb4')`,
  `$t->collation('utf8mb4_0900_ai_ci')`, `$t->rowFormat('DYNAMIC')`, `$t->comment('…')`. These
  (ROW_FORMAT / ENGINE / CHARSET / COLLATE / COMMENT) are emitted only by the MySQL grammar;
  PostgreSQL / SQLite / SQL Server ignore them (they override `compileTableOptions()` to return '').
- CHECK constraints: `$t->check('status between 1 and 3', 'chk_status')` — a raw boolean SQL
  expression (unquoted column names) + optional name. Portable: emitted inline in the CREATE
  TABLE body on all four drivers. There is no per-column `->check()` modifier — it is table-level.

**AbstractCommand API** (not Symfony — do not mix these up):
- `configure()` — set `$this->name`, `$this->description`; call `addArgument()` / `addOption()`
- `handle(): int` — read via `$this->argument()`, `$this->option()`, `$this->hasOption()`
- Output: `$this->info()`, `$this->success()`, `$this->warning()`, `$this->error()`, `$this->muted()`
- Components: `$this->ask()`, `$this->select()`, `$this->confirm()`, `$this->progressBar()`, `$this->spinner()`, `$this->table()`
- Alert boxes: `$this->alertSuccess()`, `$this->alertError()`, `$this->alertWarning()`, `$this->alertInfo()`
- Return `self::SUCCESS` (0), `self::FAILURE` (1), or `self::INVALID` (2) — never call `exit()`

---

## KERNEL.PHP — ENTRY POINT PATTERN

```php
// app/bootstrap/base.php — shared defaults (unbuilt builder)
return Kernel::configure()
    ->withPorts([
        DatabasePort::class => new MySQLAdapter(config('db')),
        CachePort::class    => new RedisAdapter(config('cache')),
        QueuePort::class    => new RedisQueueAdapter(config('jobs')),
        MailPort::class     => new SendGridMailGateway(config('sendgrid')),
    ])
    ->withSecurity([
        new FirewallLayer(blocklist: config('security.blocklist')),
        new RateLimiterLayer(store: CachePort::class, limits: config('security.limits')),
        new CsrfTokenLayer(                                  // HMAC token (WP-nonce style), NOT double-submit
            bindCookie:  'hkm_session',                     // pin to HttpOnly cookie's raw value ('' = unbound)
            lifetime:    43200,                             // SECONDS; make()/valid() lifetime MUST match
            exemptPaths: config('security.csrf_exempt'),    // e.g. ['/api']
        ),
        // CSRF: stateless HMAC(APP_KEY) token — nothing stored, no cookie trusted, empty APP_KEY fail-closes.
        // Mint with CsrfTokenLayer::make(); verify out-of-band with ::valid(). Guide: docs/ai-context/21_CSRF.md
        // JWT / API-key / session auth: provided by your AuthModule (registers a layer in boot()).
    ])
    ->withErrorPipeline(
        ErrorPipeline::notifiers([new SlackNotifier(...), new MailNotifier(...), new DatabaseErrorLogger()])
            ->fallback(new FileNotifier(logs_path('errors.log')))
            ->rules(['critical' => ['slack','mail','db','file'], 'warning' => ['db','file'], 'info' => ['file']])
    );

// projects/admin/bootstrap/app.php — admin project bootstrap
/** @var Kernel $builder */
$builder = require __DIR__ . '/../../../app/bootstrap/base.php';

return $builder
    ->withProjectPath(dirname(__DIR__))
    ->withModules([AuthModule::class, InvoiceModule::class, PaymentModule::class])
    ->build();

// bootstrap/app.php remains as a backward-compatible shim and delegates to
// projects/admin/bootstrap/app.php.

// Entry points (each materializes the kernel for its surface on first call):
$kernel->http()        // → HttpPipeline::handle(Request): Response
$kernel->cli()         // → CliPipeline::run(argv): int
$kernel->workerLoop()  // → WorkerLoop::run(queues, concurrency): void
$kernel->mode()        // → ?RuntimeMode — surface materialized for (null until first call)

// LAZY MATERIALIZATION — build() is compile-only:
//   build()  → runs BootPipeline (validate config + compile manifests). Does NOT
//              construct pipelines, wire modules, or freeze the core container.
//   first http()/cli()/workerLoop() → materialize(RuntimeMode): constructs pipelines,
//              wires every module ONCE (Provider::boot), freezes the core. Runs once.
// All three pipelines are constructed at materialize (boot() needs them), but each is a
// cheap shell — HttpPipeline & WorkerLoop defer manifest disk I/O to their OWN first run,
// so an HTTP-only process never reads the job manifest, and a CLI process never reads the
// route manifest. ->container() also materializes (defaults to RuntimeMode::Cli).

// HTTP entry points (app/public_html/index.php, app/api/server.php) resolve the
// active project from the Host header via App\Bootstrap\Domain\DomainResolver:
//   1. lookup Host in projects/projects.json (exact match wins over suffix match)
//   2. fall back to HKM_PROJECT env var, then 'admin'
//   3. load projects/{project}/bootstrap/app.php (or bootstrap/app.php as shim)
// The resolved DomainContext is attached to the kernel Request via
//   $request->withAttribute('domain', $ctx)
// CLI/worker entries stay env-driven (no Host header available).
```

Builder semantics for inheritance:
- `withPorts([...])` merges with existing bindings (later keys override earlier ones).
- `withSecurity([...])` appends layers (base first, project additions later).
- `withModules([...])` appends and de-duplicates modules while preserving order.
- `withEssentialModules([...])` marks modules as loaded into EVERY request
  container (and auto-adds them to `withModules`). See MODULE ACTIVATION below.

---

## DOMAIN RESOLUTION (PROJECT LAYER — NOT KERNEL)

Lives entirely under `app/Bootstrap/Domain/` — kernel knows nothing about it.

| Class | Role |
|---|---|
| `App\Bootstrap\Domain\DomainType` | Backed enum: `Admin`, `Api`, `Project`, `Public` |
| `App\Bootstrap\Domain\DomainContext` | `final readonly` — name, projectPath, type, host, features[] + isAdmin/isApi/isProject/isPublic/isPlatformOnly helpers + `PLATFORM` constant |
| `App\Bootstrap\Domain\DomainResolver` | `resolve(string $basePath, string $host): ?DomainContext` — two-pass match (exact then suffix), worker-level cache keyed by basePath |
| `App\Bootstrap\EntryHelpers` | `resolveDomain()`, `projectFromContext()`, `bootstrapPathFor()` — shared by entry points |

Registry files (deploy-time artifacts):
```
projects/platform.json   { "subdomains": { "admin": ["app","admin"], "api": ["api"] } }
projects/projects.json   { "<projectName>": { "domains": ["example.com","app.example.com"] } }
projects/<name>/proj.json optional — "features": [...] surfaced on DomainContext
```

Per-project runtime and config roots:
```
projects/<name>/var/       ephemeral runtime (logs/cache/manifests/tmp/locks)
projects/<name>/userdata/  persisted tenant data (uploads/reports/exports)
projects/<name>/config/    project-specific configuration files
projects/<name>/database/  project migration/seed/factory files
```

Path fallback behavior:
- If `withProjectPath()` is set, `Paths::var()/logs()/cache()/userdata()/config()` resolve under `projects/<name>/...`.
- If no project is set, they fall back to kernel/base roots (`<base>/var`, `<base>/userdata`, `<base>/config`).

Swoole/coroutine safety contract:
- `DomainContext` is NEVER bound into `CoreContainer`/`ModuleContainer` — it rides on the immutable `Request` value object so coroutines cannot bleed it.
- `DomainResolver::$cache` is per-worker, populated once per basePath, never mutated on the hot path (redeploy = new worker = cache rebuild).
- Host is normalised: lowercased, port stripped, trailing dot stripped, IPv6-bracket aware.
- Project names from JSON are validated against `/^[a-zA-Z0-9_\-]+$/` to block path traversal.

Reading the context inside a module:
```php
/** @var ?App\Bootstrap\Domain\DomainContext $ctx */
$ctx = $request->attribute('domain');
if ($ctx?->isApi()) { /* api face */ }
```

---

## ENVIRONMENT & CONFIG LOADING (PROJECT LAYER — NOT KERNEL)

Lives under `app/Bootstrap/Environment/` — kernel stays environment-agnostic.
Every entry point loads the environment + installs the error net BEFORE requiring
the kernel bootstrap.

| Class | Role |
|---|---|
| `App\Bootstrap\Environment\LoadEnvironment` | `.env` cascade loader. `load($rootPath, ?DomainContext, ?array $argv)` |
| `App\Bootstrap\Environment\ErrorGuard` | Pre-kernel + fatal-error safety net. `install(?string $logFile, bool $registerHandlers = true)` |

**`.env` three-tier cascade** (each file optional; later overrides earlier):
```
TIER 1 base     {root}/.env, then {root}/.env.{APP_ENV|--env}
TIER 2 domain   {root}/.env.{sld}, .env.{sub}, .env.{sub}.{sld}   (from host)
TIER 3 project  {projectPath}/.env (+ the same domain cascade)
```
Values already in the REAL process environment are never clobbered (OS/server wins).

**`env()` is the canonical reader — NEVER `getenv()` in first-party code.**
`LoadEnvironment` injects values into `$_ENV`/`$_SERVER` only; it does NOT call
`putenv()` by default (putenv is ~98% of injection cost and coroutine-unsafe under
OpenSwoole). So `getenv()` will NOT see `.env` values. Read config via the global
`env($key, $default)` helper (`src/Kernel/Support/helpers.php`), which checks
`$_ENV`/`$_SERVER` then falls back to `getenv()` for genuine OS vars.
```php
$secret = env('JWT_SECRET', '');        // ✅ correct
$secret = getenv('JWT_SECRET') ?: '';   // ✗ will be empty for .env-provided keys
```
Restore process-env mirroring only for a third-party SDK that reads the OS env
directly (AWS/Vault): `LoadEnvironment::useProcessEnv(true)`.

**Compiled cache (opt-in, FPM only):** off by default. Enable in production with
`ENV_CACHE=1` (or `LoadEnvironment::useCache(true)`) — writes a compiled
`var/cache/env.<scope>.php`, stat-invalidated. Under OpenSwoole env loads once per
worker, so the cache is moot there.

Entry-point order (every entry point):
```php
require vendor/autoload.php;
$domain = EntryHelpers::resolveDomain($rootPath, $host);   // HTTP only
LoadEnvironment::load($rootPath, $domain, $argv);          // 1. env first
ErrorGuard::install($logRoot . '/var/logs/errors.log');    // 2. error net
$kernel = require EntryHelpers::bootstrapPathFor(...);      // 3. then kernel
```

---

## MODULE CONTRACT — EVERY MODULE IMPLEMENTS THIS

```php
interface ModuleContract
{
    public function solves(): string;          // 'invoice.generation' — must match module.json
    public function requires(): array;         // [DatabasePort::class, PdfGatewayContract::class]
    public function exposes(): array;          // [InvoiceServiceContract::class]
    public function register(ModuleContainer $container): void;
    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void;
}
```

---

## module.json — THE SINGLE SOURCE OF TRUTH

```json
{
  "name": "invoice",
  "version": "1.0.0",
  "solves": "invoice.generation",
  "type": "module",
  "requires": ["database.query"],
  "exposes": ["InvoiceServiceContract"],
  "routes": [
    { "method": "GET",  "path": "/api/invoices",      "handler": "InvoiceController@index"  },
    { "method": "POST", "path": "/api/invoices",      "handler": "InvoiceController@create" },
    { "method": "GET",  "path": "/api/invoices/{id}", "handler": "InvoiceController@show"   }
  ],
  "views":   "resources/views",
  "emits":   ["invoice.created", "invoice.paid"],
  "listens": ["payment.succeeded"],
  "config":  ["INVOICE_CURRENCY", { "key": "INVOICE_TAX_RATE", "type": "float", "required": false }]
}
```

Plugin routes are ONLY in module.json — never in PHP files (projects declare
their own routes in proj.json; see "RESOURCE RESOLUTION").
Optional `views` (string/object/list) registers the plugin's view paths +
namespace into the project-first cascade — see "RESOURCE RESOLUTION".
Config vars declared here or boot fails with a descriptive error.

---

## RESOURCE RESOLUTION — DETERMINISTIC PROJECT-OVER-PLUGIN PRIORITY

Routes and views resolve through ONE predictable model: **project resources
always win over plugin resources by default.** Order is fixed at boot and
compiled into manifests — never implicit or load-order dependent. A plugin may
outrank the project ONLY by explicitly opting in (a lower numeric view
`priority`); the platform never lets a plugin override the project silently.

### Routes — project overrides plugin

- Plugin routes: plugin `module.json` `routes[]` → compiled FIRST, under the
  plugin's `solves` domain.
- Project routes: `proj.json` `routes[]` (or `Kernel::withRoutes([...])`) →
  compiled LAST, under the synthetic `__project__` scope (no module graph; the
  full-class-path controller autowires from the request container).
- A project route with the same `METHOD path` as a plugin route OVERRIDES it.
  Two plugins claiming the same route still hard-fail at boot.

```jsonc
// proj.json
{ "routes": [ { "method": "GET", "path": "/", "handler": "Shop\\Http\\HomeController@index" } ] }
```

**Per-route `requires`** — `__project__` has an EMPTY graph, so a project route
loads NO plugins by default (on-demand contracts are unbound). Opt one route into
a plugin without making it essential by naming module domains in `requires[]`:

```jsonc
{ "method": "GET", "path": "/dashboard", "handler": "Shop\\Http\\DashboardController@index",
  "requires": ["view.rendering"] }
```

`CompileRouteManifestStage` validates each at BOOT (unknown domain → build fails)
and `LoadStage` seeds them into THAT request's graph only
(`DependencyGraphCalculator::resolve($service, $additional)`). Scope isolation
still holds — only the plugin's PUBLIC contract resolves, not its `bindInternal`.
Choose: route `requires[]` (some routes) vs `withEssentialModules()` (every
request) vs declaring the route in the plugin's `module.json` (the endpoint IS the
plugin). Project routes also pass through `filters[]`; plugin routes may carry
`requires[]` too.

### Views — project-first cascade + namespacing

`CompileViewManifest` → `view-manifest.php`:
`{ global: [project dirs…, plugin dirs…], namespaces: { task: [dir] } }`.

Priority: LOWER wins. PROJECT view paths default **0** (highest); PLUGIN paths
default **100** (fallback). In `PhpViewRenderer`:
- `render('welcome')` → walks the global cascade (project first).
- `render('task::welcome')` → project override first (`{global}/task/welcome.php`),
  then the `task` namespace's own dir.

Declaration shapes (module.json / proj.json `views`):
`"resources/views"` | `{ "path": …, "namespace": "task", "priority": 100, "global": true }` | list.
A plugin only preempts the project via an explicit lower `priority` (e.g. `-1`).
`global:false` exposes a source under its namespace only. `VIEW_PATHS` env is
PREPENDED to the cascade (operator override; still cannot let a plugin outrank
the project).

DO: project overrides by default; use `namespace::view` to target/avoid
collisions. DON'T: rely on plugin load order; define project routes in PHP; let
two plugins claim the same route or unnamespaced view name.

---

## SECURITY GATEWAY — PRE-BOOTSTRAP

```php
// Runs BEFORE any module loads. Denied = zero module cost.
// Layer order: cheapest first.
final class SecurityGateway
{
    public function inspect(Request $request): SecurityVerdict
    {
        foreach ($this->layers as $layer) {
            $verdict = $layer->check($request);
            if ($verdict->isDenied()) return $verdict;         // short-circuit
            if ($verdict->identity()) $request = $request->withIdentity($verdict->identity());
        }
        return SecurityVerdict::allow($request);
    }
}

// Layer contract:
interface SecurityLayerContract
{
    public function check(Request $request): SecurityVerdict; // NEVER throw — return deny()
}

// Verdict:
SecurityVerdict::allow($request)          // proceed, optional Identity attached
SecurityVerdict::deny(401, 'reason')      // stop, return HTTP error immediately

// Multi-tenant context: JwtAuthLayer sets Identity.tenantId from the signed
// `tnt` claim (legacy `tenant`), default '' = unscoped → central connection. A
// non-empty tenant is routed to its isolated DB by Plugins\Tenancy
// TenantContextStage (after.load, rebinds DatabasePort). Mint a tenant-scoped
// token only after verifying central user_tenants membership; re-check per
// request. Control-plane plugins (User, Auth) pin to the ConnectionManager
// default (central) so identity I/O never hits a tenant DB. See 09_SECURITY.md.

// Identity — immutable, set once by your AuthModule's security layer:
final readonly class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly array  $roles,
        public readonly array  $permissions,
        public readonly string $tokenType,  // 'jwt' | 'api_key' | 'session'
    ) {}
    public function hasRole(string $role): bool       { return in_array($role, $this->roles, true); }
    public function hasPermission(string $perm): bool { return in_array($perm, $this->permissions, true); }
    public function isGuest(): bool                   { return empty($this->userId); }
}
```

---

## HTTP PIPELINE — 6 STAGES + HOOK SYSTEM

```text
Request arrives
    │
    ▼
1. CorrelationIdStage    — generate/propagate X-Correlation-ID (always first)
2. SecurityStage         — run SecurityGateway, attach Identity on clear
   after.security hooks  — module stages (e.g. RateLimiterStage priority:10)
3. ResolveStage          — route-manifest.php lookup → service name (attaches route_entry)
4. LoadStage             — dep graph calc → OnDemandLoader → ModuleContainer
   after.load hooks      — module stages
   RouteFilterStage      — runs the matched route's declared filters[] (auth, throttle, …)
5. ExecuteStage          — resolve service contract → DTO → handler → Response
   after.execute hooks   — module stages
6. ErrorStage (wraps all) — catches all Throwables → ErrorPipeline → HTTP response
```

Stage contract:
```php
interface HttpStageContract
{
    public function handle(Request $request, callable $next): Response;
}
```

Registering a hook from a module's boot():
```php
public function boot(HttpPipeline $http, ...): void
{
    $http->hook('after.security', RateLimiterStage::class, priority: 10);
    // Priority: 1-9 system, 10-19 security, 40-59 feature, 80-99 observability
}
```

### TWO WAYS A STAGE RUNS — GLOBAL HOOK vs DECLARATIVE ROUTE FILTER

A stage (`HttpStageContract`) runs through EXACTLY ONE mechanism — never both
(double-registering would double-run it; e.g. a rate limiter would double-count).

| Aspect | Global hook | Declarative route filter |
|---|---|---|
| Register | `$http->hook(slot, Stage::class, priority)` | `$http->filter('alias', Stage::class)` |
| Runs on | EVERY request (stage self-gates internally) | ONLY routes that name the alias |
| Declared where | the registering plugin's `boot()` | the route's `filters[]` in module.json / proj.json |
| Use for | always-on cross-cutting (CORS, SecureHeaders) | opt-in per route (auth, throttle, hmac, shield) |

Route filters are declared per route and compiled into the route manifest:
```jsonc
// module.json or proj.json route entry
{ "method": "POST", "path": "/api/tasks", "handler": "...@create",
  "filters": ["auth", "throttle:60,1"] }   // string or list; "alias:arg1,arg2" passes args
```

Any plugin publishes filter aliases from its `boot()` (the alias registry is
shared — not owned by SecurityFilters):
```php
public function boot(HttpPipeline $http, ...): void
{
    $http->filter('json', RequireJsonStage::class);   // route opts in via "filters": ["json"]
}
```

How a route filter executes ([RouteFilterStage] at the after.load position):
- Reads the matched route's `filters[]` from `route_entry` (set by ResolveStage).
- Resolves each alias via [FilterRegistry] to a stage instance (from CoreContainer
  when bound, else `new`), and runs them as a NESTED onion around `$next` — so the
  usual before/after semantics hold (code before `$next()` = inbound/short-circuit;
  after = decorate Response). Filters run left-to-right in declaration order.
- Exposes `active_filters` (alias list) + `filter_args` (parsed `:args`) as request
  attributes, so a stage can tell it was invoked declaratively and read its config.
- Unknown alias → throws at request time (register it in some Provider::boot()).

```
✗ Registering the SAME stage as BOTH a global hook AND a route filter — it runs twice
✗ Naming a filter alias in a route that no Provider::boot() registered — request-time throw
✗ Defining route filters anywhere but the route's filters[] in module.json / proj.json
```

---

## HTTP REQUEST / RESPONSE — USAGE

`Request` and `Response` are FINAL, IMMUTABLE value objects built on
symfony/http-foundation (transitional — depend on the kernel method surface below, NOT on
Symfony classes). Every `with*`/`merge`/`replace` returns a NEW instance — reassign, never
mutate (Swoole-safety). One `Response` type everywhere; controllers/stages are typed
`: Response` (do NOT return Symfony response subclasses — they break the contract).

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};

// --- Read input (body+query merged; JSON auto-decoded) ---
$request->method();            $request->path();            $request->isMethod('post');
$request->body();              // parsed BODY only (JSON/form), excludes query
$request->all();               // body + query
$request->input('title', 'x'); $request->only([...]);       $request->except([...]);
$request->boolean('active');   $request->integer('page');   $request->string('q');
$request->query('page');       $request->header('Accept');  $request->cookie('sid');
$request->bearerToken();       $request->file('avatar');    // ?UploadedFile
$request->expectsJson();       $request->is('api/*');       $request->segments();
$request->identity();          $request->attribute('domain'); // ?Identity / ?DomainContext

// --- Immutable mutation (reassign!) ---
$request = $request->withAttribute('locale', 'fr')->merge(['source' => 'import']);

// --- Build responses ---
return Response::json($dto->toArray(), 201);
return Response::created($dto->toArray(), location: "/api/x/{$id}");
return Response::noContent();                       // 204
return Response::notFound();  Response::forbidden();  Response::unauthorized();
return Response::unprocessable(['email' => 'Required.']);   // 422 + fields
return Response::redirect('/login');  Response::back($request->header('referer'));
return Response::download($path, 'r.pdf');          // sendfile() under Swoole
return Response::stream(fn() => print($csv));        // chunked, both transports
return Response::json($data)->withHeader('Cache-Control','no-store')
                            ->withCookie('sid', $tok, maxAge: 3600);

// --- URL + negotiation helpers (off the request) ---
$callback = $request->site()->to('auth/callback');  // absolute, host from request
$canon    = (string) $request->uri()->withQuery(''); // PSR-7 Uri manipulation
$locale   = $request->negotiate()->language(['en','fr']);
```

Error body shape (always): `{ "error": { "code", "message"[, "requestId"][, "fields"] } }`.
Uploads: `$request->file($k)?->move($dir, $name)` — FPM keeps `is_uploaded_file()` safety;
the Swoole adapter injects files via `UploadedFile::fromSwoole()`. Both transports are
emitted correctly (FPM `Response::send()`; Swoole reads status/headers/cookies/body or
`sendfile`/chunked).

---

## CLI PIPELINE — STAGES + AbstractCommand PATTERN

```text
$ php cli.php invoice:generate-monthly --dry-run
    ▼
CorrelationIdStage       ← generate CommandId for log tracing
AuthenticateCommandStage ← operator credentials for protected commands (optional)
ResolveCommandStage      ← match argv[1] to a registered command name
OnDemandLoaderStage      ← dep graph → load only needed modules
ValidateArgsStage        ← validate arguments against addArgument() defs
ExecuteCommandStage      ← call command->execute(tokens, $io) → exit code
ErrorStage (wraps all)   ← route uncaught errors to ErrorPipeline
```

**Registration in Provider::boot():**
```php
public function boot(HttpPipeline $http, CliPipeline $cli, ...): void
{
    $cli->command(GenerateMonthlyInvoicesCommand::class);
}
```

**Canonical command — COPY THIS SHAPE:**
```php
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use InvoiceModule\API\Contracts\InvoiceServiceContract;

final class GenerateMonthlyInvoicesCommand extends AbstractCommand
{
    public function __construct(
        private readonly InvoiceServiceContract $invoices, // injected via CoreContainer
    ) {}

    protected function configure(): void
    {
        $this->name        = 'invoice:generate-monthly';
        $this->description = 'Generate monthly invoices for all active clients';

        $this->addArgument('month', 'Target month (Y-m)', required: false);
        $this->addOption('dry-run', 'd', 'Simulate — no invoices created');
        $this->addOption('tenant',  't', 'Restrict to one tenant', acceptsValue: true);
        // acceptsValue: true → --tenant=abc  or  --tenant abc
        // acceptsValue: false (default) → boolean flag --dry-run
    }

    protected function handle(): int  // NO parameters — read input via $this->*
    {
        $month  = $this->argument('month', date('Y-m')); // string|null with default
        $dryRun = $this->hasOption('dry-run');           // bool
        $tenant = $this->option('tenant');               // mixed|null

        $this->section('Invoice Generation');
        $this->info("Month: {$month}" . ($dryRun ? ' [dry-run]' : ''));

        if (!$this->confirm('Proceed?')) {
            $this->muted('Aborted.');
            return self::SUCCESS;
        }

        $bar = $this->progressBar('Generating', 0); // 0 = indeterminate bounce
        $bar->start();

        try {
            $result = $this->invoices->generateMonthly(
                new GenerateMonthlyInvoicesDTO(month: $month, dryRun: $dryRun, tenantId: $tenant)
            );
        } catch (\Throwable $e) {
            $bar->finish('Failed');
            $this->alertError('Generation failed', [$e->getMessage()]);
            return self::FAILURE;
        }

        $bar->finish('Done');
        $this->alertSuccess("Generated {$result->created} invoices", ["Skipped: {$result->skipped}"]);
        return self::SUCCESS; // always return a constant — never call exit()
    }
}
```

**Output methods (NOT Symfony — these are the only correct calls):**
```php
$this->info('message');          // cyan
$this->success('message');       // ✔ green
$this->warning('message');       // ! yellow (stderr)
$this->error('message');         // ✘ red   (stderr)
$this->muted('message');         // dim gray
$this->section('Title');         // bold cyan heading
$this->newLine(2);
$this->alertSuccess('title', ['body line 1', 'body line 2']);
$this->alertError('title', ['...']);
$this->alertWarning('title');
$this->alertInfo('title');
```

**Interactive component shortcuts:**
```php
$text    = $this->ask('Question', default: '');
$choice  = $this->select('Pick one', ['a', 'b', 'c']);
$bool    = $this->confirm('Sure?', default: true);
$bar     = $this->progressBar('Label', total: 100); // total=0 → indeterminate
$spin    = $this->spinner('Label', style: 'dots');
$table   = $this->table(); // → Table::make() — call ->headers()->rows()->render()
```

**Shell execution (use instead of exec/shell_exec/proc_open directly):**
```php
use AlfacodeTeam\PhpIoCli\Depends\Shell;

// Streaming with animated progress
$result = Shell::run(
    command: 'composer install --no-dev',
    tick:    fn() => $bar->advance(0), // advance(0) = redraw without incrementing
    cwd:     '/var/www/app',
);
if ($result->failed()) {
    $this->alertError('Failed', $result->meaningfulErrors());
    return self::FAILURE;
}
$bar->advance(); // step complete — bar moves forward

// Quick value capture
$branch = Shell::capture('git rev-parse --abbrev-ref HEAD', cwd: $root); // string|null
```

**Testing commands (use BufferIO — never instantiate ConsoleIO in tests):**
```php
use AlfacodeTeam\PhpIoCli\BufferIO;

$io = new BufferIO();
$io->setUserInputs(['y']); // one string per interactive prompt, in order

$exit = (new MyCommand(new FakeService()))->execute(['arg1'], $io);

$this->assertSame(AbstractCommand::SUCCESS, $exit);
$this->assertStringContainsString('expected text', $io->getOutput()); // ANSI-stripped
```

---

## CONTAINER ARCHITECTURE — bind-it ENGINE

Both `CoreContainer` and `ModuleContainer` extend `PHPShots\Common\Container` from the **bind-it**
package (`phpshots/bind-it`). This gives reflection-based autowiring, contextual bindings,
extenders, and PSR-11 compliance as a foundation. GDA scope rules are layered on top.

**CoreContainer** — app-lifetime (one per worker process):
```php
$core->instance(DatabasePort::class, $mySQLAdapter);   // store port implementation
$core->singleton(TransactionManager::class, fn($c) => new TransactionManager(...));
$core->freeze();        // called during materialize() (first entry-point call) — writes forbidden after
$core->isFrozen(): bool // false after build(), true after materialize()
// getInstance() and setInstance() are DISABLED — throw LogicException (no global singleton)
```

**ModuleContainer** — request-scoped (new instance per request, discarded after):
```php
// Internal binding — throws ScopeViolationException from outside this module
$container->bindInternal(InvoiceRepository::class, fn($c) =>
    new InvoiceRepository($c->make(DatabasePort::class))
);

// Public binding — resolvable by modules that declare this in requires[]
$container->bind(InvoiceServiceContract::class, fn($c) =>
    new InvoiceService(
        repository:  $c->make(InvoiceRepository::class),
        transaction: $c->make(TransactionManager::class),
        collector:   $c->make(DomainEventCollector::class),
        eventBus:    $c->make(EventBus::class),
        identity:    $c->make(Identity::class),
    )
);

// Resolve with explicit caller scope (used by ExecuteStage when calling controllers)
$container->makeInScope(InvoiceController::class, 'invoice.generation');

// Full lifecycle teardown (call at end of each Swoole request to prevent leaks)
$container->reset();
// getInstance() and setInstance() are DISABLED — throw LogicException
```

---

## EVENT SYSTEM — TWO TYPES

| Aspect | Domain Event | Integration Event |
|---|---|---|
| Scope | Internal to module | Cross-module |
| Timing | Collected DURING transaction | Dispatched AFTER commit only |
| On rollback | `collector->discard()` — no phantom events | Never dispatched |
| Constructor | Domain value objects | Primitive types only (string/int/float) |

Domain events:
```php
final readonly class InvoiceCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public readonly InvoiceId $invoiceId,
        public readonly Money     $total,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
```

Integration events:
```php
final readonly class InvoiceCreatedIntegrationEvent implements IntegrationEventContract
{
    public string $version = '1.0';
    public function __construct(
        public readonly string $invoiceId,  // primitives only
        public readonly float  $amount,
        public readonly string $occurredAt, // RFC3339 string
    ) {}
    public function name(): string    { return 'invoice.created'; }
    public function version(): string { return $this->version; }
    public function payload(): array  { return get_object_vars($this); }
}
```

---

## MANDATORY TRANSACTION PATTERN (in Service layer)

```php
$this->collector->beginCollection();
$this->transaction->begin();
try {
    $entity = Entity::create(...);
    foreach ($entity->releaseEvents() as $e) { $this->collector->collect($e); }
    $this->repository->save($entity);
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();  // ALWAYS — no phantom events on rollback
    throw new ServiceException('module.action.failed', previous: $e);
}
// Integration event dispatch ONLY here — after successful commit
$this->eventBus->dispatch(new EntityCreatedIntegrationEvent(...));
```

---

## PORT INTERFACES (kernel defines, project implements)

```php
interface DatabasePort {
    public function query(string $sql, array $params = []): array;
    public function queryOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;
    // Portable, atomic upsert (MySQL ON DUPLICATE KEY / PostgreSQL+SQLite ON CONFLICT).
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int;
    public function lastInsertId(?string $sequence = null): string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}

interface CachePort {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
}

interface QueuePort {
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string;
}

interface MailPort  { public function send(string|array $to, string $subject, string $view, array $data = []): void; }
interface SmsPort   { public function send(string $to, string $message): void; }
interface StoragePort {      // adapter: plugins/Storage (local disk OR S3/Flysystem)
    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string;
    public function storeStream($resource, string $filename, string $path = '', string $visibility = 'private'): string;
    public function get(string $path): string;
    public function readStream(string $path);                          // caller closes the handle
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
}

interface HttpClientPort {   // outbound HTTP for Gateways — adapter: plugins/HttpClient (cURL)
    public function request(string $method, string $url, array $options = []): HttpClientResponse;
    public function get(string $url, array $query = []): HttpClientResponse;   // + post/put/patch/delete
    public function pending(): PendingRequestContract;   // immutable fluent builder (reachable via the PORT)
}
// Retries idempotent-only by default (GET/HEAD/PUT/DELETE/OPTIONS/TRACE) on transport
// failure AND transient 5xx/429; widen with ->retryMethods([...]) / 'retry_methods'.
// Coroutine-aware backoff. CR/LF header injection rejected; multipart names sanitized.
// JSON bodies use JSON_THROW_ON_ERROR. Response capped at HTTP_CLIENT_MAX_RESPONSE_BYTES (32 MiB).

interface SessionPort {      // request-scoped — adapter: plugins/Session (essential module)
    public function start(?string $id = null): void;
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value): void;
    public function flash(string $key, mixed $value): void;   // + pull/push/has/forget/flush/all
    public function token(): string;                          // CSRF
    public function regenerate(): void; public function invalidate(): void;
    public function shouldPersist(): bool; public function save(): void;  // lazy persistence
}
// Drivers: SESSION_DRIVER=file (default) | array | cookie.
//   cookie = stateless, state stored IN the cookie (no server store; multi-node safe).
//     Encrypted via EncryptionPort when APP_KEY/Crypto present, else HMAC-signed
//     (SESSION_SIGNING_KEY→APP_KEY). Absolute SESSION_LIFETIME + sliding
//     SESSION_IDLE_TIMEOUT (enforced server-side), fingerprint binding
//     (SESSION_COOKIE_FINGERPRINT=off|ua|ip|ua,ip), compression
//     (SESSION_COOKIE_COMPRESS), size ceiling (SESSION_COOKIE_MAX_BYTES), and boot
//     guards SESSION_COOKIE_REQUIRE_AUTH / SESSION_COOKIE_REQUIRE_ENCRYPTION.
//     Keep <4KB — use file/Redis for large state. See 20_FIRST_PARTY_PLUGINS.md.
```

Controllers reach the session via the `InteractsWithSession` trait on the
`ApiController` / `ViewController` bases (`$this->sessionGet/Put/Pull/Forget`,
`flash`, `csrfToken`, `regenerateSession`, `invalidateSession`). It shares the
`HasRequest` concern with `InteractsWithCookies`. Never inject `SessionPort` into a
Service/Repository — it is a controller-layer (project) concern.

---

## MODULE ACTIVATION — ON-DEMAND vs ESSENTIAL vs PORT

A module's `register()` (DI bindings) runs per request ONLY when the module is in
that request's dependency graph (built from the resolved route service's
transitive `requires[]`). Three ways a capability reaches a request:

1. **App-lifetime port** — `withPorts([...])` into `CoreContainer`; always
   resolvable via the ModuleContainer core fallback. STATELESS infra only (DB,
   hashing, encryption, in-memory cache). Never for request-scoped state (leaks
   under OpenSwoole).
2. **On-demand module** — `withModules([...])`; `boot()` hooks register at build,
   but `register()` runs only when a route pulls it in. A consumer opts in via
   `module.json`: `{ "requires": ["http.client", "storage.local", "view.rendering"] }`.
   Use for capabilities only some routes need (outbound HTTP, file storage, HTML views).
3. **Essential module** — `withEssentialModules([...])`; registered into EVERY
   request container regardless of the graph. For cross-cutting REQUEST-SCOPED
   infra that cannot be an app-lifetime port (sessions, cookies, Redis cache
   override). Keep adapters self-guarding; each pays a per-request `register()`.

Rule of thumb: stateless + always → port; stateful + always → essential;
needed by some routes → on-demand with `requires[]`.

First-party plugins (`plugins/`, namespace `Plugins\`; see `docs/ai-context/20_FIRST_PARTY_PLUGINS.md`):

| Plugin | solves | Provides | Activation |
|---|---|---|---|
| Storage | `storage.local` | `StoragePort` (local disk + S3/Flysystem; atomic+fsync writes, stream up/download, signed URLs; `storage_config()` / `STORAGE_*`; empty `STORAGE_S3_KEY` ⇒ IAM role chain) | on-demand |
| View | `view.rendering` | `ViewRendererContract` (PHP templates: layouts, sections, decorators; project-first cascade + `ns::view`) | on-demand |
| HttpClient | `http.client` | `HttpClientPort` (cURL, fluent `pending()` via port, idempotent-safe retries, header/multipart hardening, response size cap) | on-demand |
| Session / Cookie / RedisCache | session/cookies/cache | `SessionPort` (file/array/cookie) / `CookieJar` / `CachePort`+`QueuePort` | essential |
| SecurityFilters | `http.security_filters` | global hooks: CORS, SecureHeaders. Route-filter aliases: `auth`, `throttle`, `hmac`, `shield` | hooked + filters |
| Pageflow | `http.pageflow` | `PageflowResponder` (SPA bridge) | on-demand |
| I18n | `i18n.translation` | `Translator` (file-based `{dir}/{locale}/{group}.php`, dotted `group.key`; `:name`/`:Name`/`:NAME` placeholders substituted longest-first via `strtr`; `choice()` pluralization `singular\|plural` or ranges `{0}`/`[1,19]`/`[20,*]`; never throws → missing key returns key). `LocaleStage` (after.load p45) negotiates `Accept-Language` vs `APP_LOCALES` + binds the global `__()`/`trans()`/`trans_choice()`/`lang_has()` helpers. Env `APP_LOCALE`/`APP_FALLBACK_LOCALE`/`APP_LOCALES`/`APP_LANG_PATH` | on-demand (`requires:[]`) |
| SiteSEO | `seo.management` | `SeoServiceContract` — Open Graph/Twitter, Schema.org JSON-LD, sitemaps, robots.txt, sitemap ping + **IndexNow** (`indexNow()` auto-batches 10k, lazy iterable, dry-run; `SearchEngineGateway` → `HttpClientPort`). Bg job `seo.indexnow` + `UrlPublishedIntegrationEvent`/`EnqueueIndexNowListener` for index-on-publish | on-demand (`requires:["http.client"]`) |
| Tenancy | `tenancy.routing` | Multi-tenant control plane ONLY (NOT authentication): `TenantRegistryContract` + `TenantConnectionResolverContract` + `MembershipServiceContract` + `InvitationServiceContract` + `TenantAdminServiceContract`. Maps `Identity.tenantId` → isolated tenant `DatabasePort`, rebinds per request (`TenantContextStage` @ `after.load`). Fail-closed + per-tenant circuit breaker; central `tenants`/`user_tenants`/`tenant_invitations`/`audit_log`/`tenant_hosts`; database-per-tenant. Selection flow `GET /ajx/me/tenants` + `POST /ajx/tenants/{id}/select` (re-verifies membership, mints `tnt` token) — the tenant seat check lives HERE, at selection; email invitations → seat. Admin CRUD `/ajx/admin/tenants` is platform-admin-gated in BOTH the controller and `TenantAdminService` (`tenancy:admin`/`platform-admin`). Publishes the **`tenant` route filter** (`RequireTenantStage` → 409 when no active tenant) for tenant-only routes. CLI `tenants:create`/`tenants:migrate`. **Refresh tokens moved to `Plugins\Auth`** (authentication ≠ tenancy) | essential (`requires:["database.management","auth.identity","user.management"]`) |
| User | `user.management` | GLOBAL central identity: `UserServiceContract` (register/CRUD, email verification, timing-safe + rate-limited credential verify, rehash-on-login). Login gate = verified email (`email_verified_at`); NO `status` column. Repo + transactional outbox pinned to CENTRAL; optimistic-locked; emits `user.registered/updated/deleted`; optional HIBP screening (`USER_BREACH_CHECK`). CLI `user:outbox:relay`. ALSO owns TENANT-scoped sub-resources (internal, NOT published, repos use the request/tenant `DatabasePort`, schema in `database/tenant-template/`): **feedback** (`POST/GET/PATCH /ajx/feedback`, emits `feedback.submitted`) and **settings** (one `UserSettingsService` → `GET/PUT /ajx/{profile,preferences,privacy,notification-preferences}`). Tenant routes declare `["auth","tenant"]`. Guide `docs/ai-context/24_USER.md` | on-demand (`requires:["database.management","crypto.services","cache.redis","view.rendering","http.client"]`) |
| Auth | `auth.identity` | Authn — the ONLY home for sessions/tokens. ISSUANCE `AuthServiceContract` (JWT iss/aud/jti, RS/ES/PS signing; PATs w/ expiry+abilities; session login + remember-me `Recaller`). VERIFICATION SecurityLayers in `withSecurity([...])`: `JwtAuthLayer` (jti deny-list) + `PersonalAccessTokenLayer`; `SessionAuthStage` @ after.load p22. **`RefreshTokenServiceContract`** (revocable first-party sessions, one-time-use rotation + family reuse detection, `refresh_tokens` table, tenant-agnostic — `tnt` passthrough hint, NOT re-verified). **`AuthManager`** = named guards+providers (`config/auth.php`, filesystem-scanned `Drivers/`); `ModelUserProvider`+`AuthUserProxy` (HasApiTokens, emits `Identity`); `StatefulSessionGuard` (attempt/login/logout/once/basic/viaRemember); `Guard` projection w/ **hierarchical scope inheritance** (`ScopeInheritance` — `admin` ⊇ `admin:write`); `PasswordBroker` (CachePort reset tokens). Routes `/auth/{login,logout,me}`, `/auth/tokens` (PAT self-mgmt), `/auth/token/refresh` (transient SPA token), `/auth/refresh` (refresh rotation). Concerns `InteractsWithAuth`/`InteractsWithAuthManager`. CLI `auth:tokens:prune`. Guide `docs/ai-context/25_AUTH.md` | on-demand (SecurityLayers wired in bootstrap; `requires:["database.management","crypto.services","user.management"]`) |
| OAuth2 | `oauth.server` | Native OAuth 2.1 + OIDC authz server (on `firebase/php-jwt`, no new vendor). Grants: authorization_code(+PKCE), client_credentials, refresh_token(rotation+family reuse-detection), password, device_code(RFC 8628). Endpoints `/oauth/{authorize,token,device_authorization,device,userinfo,introspect,revoke,jwks}` + RFC 8414/OIDC discovery. Access tokens are platform JWTs (verified by Auth `JwtAuthLayer`); scopes namespaced `scope:*` in `permissions`; `aud`=resource + `azp`=client; revoke=refresh-family + JWT `jti` deny-list. Self-service mgmt API (owner-scoped): `/oauth/clients` (CRUD, `owner_id`), `/oauth/authorized-tokens` (list/revoke granted apps), `/oauth/scopes` (`ScopeRegistry` catalogue w/ descriptions). CONTROL-PLANE → serve on apex/central host (set `TENANCY_BASE_DOMAINS` in host mode). CLI `oauth:client:{create,list,revoke,rotate}`/`oauth:prune`. Guide `docs/ai-context/26_OAUTH2.md` | on-demand (`requires:["database.management","crypto.services","user.management","view.rendering"]`) |

View notes: no globals (paths/extensions/decorators/escaper injected), request-scoped
(no cross-request leak under OpenSwoole), `SidebarManager` icon cache is instance-scoped
not `static`. Escape ONCE — pre-escape via `setVar(..., 'html')` OR escape in-template,
never both.

Cookie notes: config in `plugins/Cookie/config/cookie.php` (env-driven; project copy at
`projects/<name>/config/cookie.php` wins via `Paths::config()`). Env: `COOKIE_LIFETIME`
(minutes), `COOKIE_PATH`, `COOKIE_DOMAIN`, `COOKIE_SECURE` (set `false` for local http://),
`COOKIE_HTTP_ONLY`, `COOKIE_SAME_SITE`, `COOKIE_ENCRYPT_EXEMPT`. Helpers (autoloaded):
`cookie_config(?key)`, `cookie(name, value, minutes?, overrides?)` → spread-ready array for
`CookieJar::queue()` / `Response::withCookie()`. `CookieJar::queue()` attrs are nullable →
fall back to config defaults. `.env` gotcha: a comment-only value (`KEY=  # note`) resolves
to empty — keep inline comments on their own line.

Storage notes: config in `plugins/Storage/config/storage.php` (env-driven; project copy at
`projects/<name>/config/storage.php` wins via `Paths::config()`). Helper (autoloaded):
`storage_config(?dotted-key, default)` — e.g. `storage_config('s3.bucket')`,
`storage_config('local.root')`. Env: `STORAGE_DRIVER` (`local`|`s3`), `STORAGE_ROOT`,
`STORAGE_URL_BASE`, `STORAGE_URL_SECRET`, `STORAGE_S3_BUCKET`, `STORAGE_S3_REGION`,
`STORAGE_S3_KEY`/`STORAGE_S3_SECRET` (leave empty on EC2/ECS/EKS → AWS IAM role chain),
`STORAGE_S3_ENDPOINT`, `STORAGE_S3_PATH_STYLE`. Local writes are atomic + fsync'd with
short-write detection; use `storeStream()`/`readStream()` for large blobs.

SEO notes (SiteSEO + `Project\Support\Seo\` + traits `InteractsWithSeo` /
`InteractsWithGraphSeo`): sitemap / Open Graph / Schema.org JSON-LD building is
DI-free (toolkit autoloads — NO module needed); only network actions (sitemap
ping, IndexNow) need `requires:["seo.management"]` (→ `HttpClientPort`, never raw
cURL). `RouteCatalog` reads the route manifest for public static pages;
`{param}` routes are expanded from the DB by a `SitemapUrlProvider` (keyset-cursor
GENERATOR) and stitched by `SitemapSource` (`uncoveredDynamicRoutes()` guards
omissions). Huge sitemaps → `SitemapStreamWriter` (stream→split files+index,
O(1) memory, 50k split, gzip) — never an array/SimpleXML DOM. Rich results →
one `RichGraph` `@graph` per page (org→website→webPage→breadcrumb→content node,
linked by `@id`; types incl. article/news/blog, product, book, course,
realEstate, pageant/award/contestant, faq). `SeoHead` renders the full `<head>`
(canonical, robots, hreflang, OG, JSON-LD). IndexNow: `indexNow()` auto-batches
10k from a lazy iterable (`dryRun` for previews); large runs enqueue one
`seo.indexnow` job per batch via `QueuePort` (`FileQueue` is the no-Redis
fallback). Index-on-publish: dispatch `UrlPublishedIntegrationEvent` after
commit → `EnqueueIndexNowListener` enqueues. EventBus resolves listeners from the
CoreContainer (`has()` is bound-only), so the PROJECT binds the listener with its
`QueuePort` in `bootstrap/app.php`; the plugin only declares the subscription.
Env: `INDEXNOW_KEY` (listener no-ops without it), `INDEXNOW_LIVE`.

I18n notes (`Plugins\I18n`): lang files `{APP_LANG_PATH|plugin/lang}/{locale}/{group}.php`
`return` an array; read via dotted `group.key`. Translation NEVER throws — miss →
fallback locale → key itself. Placeholders substitute THREE cases (`name` fills
`:name`/`:Name`/`:NAME`) and LONGEST key first (`strtr`), so `:min` can't corrupt
`:minutes` — do NOT regress to a per-key `str_replace` loop. `choice($key,$count)`
splits on `|`: simple `singular|plural` (count===1→first) OR ranges `{0}`/`[1,19]`/
`[20,*]`; `:count` auto-supplied; use ranges for 3+ forms. `has()` tests the target
locale only (no fallback). `LocaleStage` (after.load p45, registered in
`Provider::boot()`) negotiates the request locale from `Accept-Language` vs
`APP_LOCALES` and binds the Translator into `Plugins\I18n\Support\Lang` for the
request (cleared in `finally` — no Swoole leak); a `__project__` route must declare
`"requires":["i18n.translation"]` for the stage to bind. Global helpers
(`plugins/I18n/Support/helpers.php`, composer `files` autoload): `__()`/`trans()`/
`trans_choice()`/`lang_has()` — degrade to key/false when unbound (CLI/worker).
Project overrides the built-in catalogue by pointing `APP_LANG_PATH` at its own
`resources/lang` (psp-shop ships `en`/`fr`/`es` + demo `GET /i18n`,`/i18n/advanced`).
Read config via `env()`, never `getenv()`.

## Base controllers + RequestAware

Optional base classes in `projects/Http/Controllers/` (namespace `Project\`; project layer,
NOT kernel — views/cookies are plugin concerns):
- `ApiController` — JSON helpers (`ok`, `created`, `noContent`, `paginated`, `okOrNotFound`,
  `notFound`, `forbidden`, `unprocessable`, `identity`); pure kernel types.
- `ViewController` — HTML helpers (`view`, `viewNotFound`, `redirect`, `back`); injects
  `ViewRendererContract`.
- All four concerns compose via the shared `HasRequest` concern (flattened once, no conflict):
  - `InteractsWithCookies` — wraps every public `CookieJar` method, no `$request` arg.
  - `InteractsWithSession` — `sessionGet/Put/Has/Pull/Forget`, `flash`, `csrfToken`,
    `regenerateSession`, `invalidateSession`.
  - `InteractsWithProject` — reads `DomainContext` off the request (`attribute('domain')`):
    `project`/`requireProject`, `projectName/Path/Face/Host`,
    `isAdmin/isApi/isProject/isPublic/isPlatformOnly`, `projectFeatures/hasFeature/feature`.
    Degrades to null/false/`[]` with no context (CLI/worker).
  - `InteractsWithStorage` — wraps the on-demand `StoragePort` (local disk or S3):
    `storage/storageAvailable`, `storeUpload`/`storeUploadAs`/`storeBase64`/`storeContents`,
    `readFile/fileExists/fileUrl/deleteFile/copyFile/moveFile`. Route MUST declare
    `"requires": ["storage.local"]`; read helpers return null/false when absent, write helpers throw.

Both implement the kernel contract `Kernel\Http\Contracts\RequestAware`
(`setRequest(Request): static`). `ExecuteStage` calls `setRequest($request)` then invokes a
RequestAware action as `$method(...$routeParams)` — **without `$request`** (raw request stays
at `$this->request`). Plain controllers keep `$method($request, ...$params)` — backward
compatible. Don't add `$request` to a RequestAware action; don't call `CookieJar::applyTo()`
from a controller (the flush stage does it).

---

## OPENSWOOLE SERVER — CONFIG FROM .env

`app/api/server.php` loads the base `.env` in the MASTER process, then reads its
own settings via the `env()` helper (never `getenv()`, which can't see `.env`).
Real OS env vars still override `.env` (env() precedence). Config keys:
`SWOOLE_HOST`, `SWOOLE_PORT`, `HKM_WORKERS`, `HKM_ENV`, `SWOOLE_COROUTINE`,
`SWOOLE_MAX_REQUEST`, `SWOOLE_DAEMONIZE`. Workers reload `.env` per worker in
`workerStart` for the per-project cascade + kernel build. The per-worker kernel is
held in a by-reference closure variable (NOT a dynamic property on the Server —
that is deprecated in PHP 8.4). `enable_coroutine` defaults to FALSE: keep it off
unless the whole request path is verified coroutine-safe (some kernel singletons
like `DependencyGraphCalculator` carry mutable per-resolve state).

---

## EXCEPTION HIERARCHY

```php
abstract class FrameworkException extends \RuntimeException
{
    public function __construct(string $message,
        public readonly string $layer   = '',   // e.g. 'service.invoice'
        public readonly array  $context = [],   // typed key→value for error log
        int $code = 0, ?\Throwable $previous = null
    ) { parent::__construct($message, $code, $previous); }
}

class SecurityException   extends FrameworkException {} // → 401/403, warning
class DomainException     extends FrameworkException {} // → 422, info
class ServiceException    extends FrameworkException {} // → 422/500, warning
class RepositoryException extends FrameworkException {} // → 500, critical
class GatewayException    extends FrameworkException {} // → 502, critical
class KernelException     extends FrameworkException {} // → 500, critical
class ValidationException extends FrameworkException   // → 422 with field errors
{
    public function __construct(public readonly array $errors, string $message = 'Validation failed.')
    { parent::__construct($message, layer: 'validation'); }
}
class ScopeViolationException      extends \RuntimeException {}
class CircularDependencyException  extends \RuntimeException {}
class BootFailureException         extends \RuntimeException {}
```

Throw rules:
- Domain layer  → `throw new \DomainException(...)` (PHP built-in)
- Service layer → `throw new ServiceException('module.action.verb', layer: '...', previous: $e)`
- Repository    → `throw new RepositoryException(...)` translated from `\PDOException`
- Gateway       → `throw new GatewayException(...)` translated from ALL vendor exceptions
- Security      → `return SecurityVerdict::deny(401, 'reason')` — NEVER throw

---

## TWO ERROR LAYERS (nested nets)

| Layer | Scope | Catches | Output |
|---|---|---|---|
| **Kernel `ErrorStage` / `ErrorPipeline`** | inside a built kernel (HTTP/CLI/worker) | `Throwable`s in a running pipeline | classify → notifiers (Slack/Mail/DB/File); generic JSON, or the debug page for a browser in debug |
| **`App\Bootstrap\Environment\ErrorGuard`** | SAPI-level, alive before the kernel exists | pre-kernel throws (e.g. `base.php` APP_KEY), PHP **fatals/parse/OOM** | generic 500, or the debug page; never leaks in production |

- Both write to ONE log: `{project}/var/logs/errors.log` (ErrorPipeline via `FileNotifier`; ErrorGuard appends a compatible JSON line tagged `source=error_guard`).
- ErrorGuard does NOT call into the ErrorPipeline (no global singletons) — the connection is the shared log file only.
- **Debug page:** `src/Kernel/Error/DebugPageRenderer` (kernel, dependency-free) renders the rich HTML page (source preview + expandable trace) — gated behind `APP_DEBUG=true`, and only for real browser navigations. API/AJAX/JSON callers (`expectsJson()` / `$_SERVER` headers / `/api` prefix) always get JSON. NEVER renders in production.
- Production error responses are generic and secret-free; `display_errors` is forced off in non-debug by ErrorGuard.

---

## JOB CONTRACT (Worker Pipeline)

```php
interface JobContract
{
    public function handle(JobPayload $payload): JobResult;
    public function failed(JobPayload $payload, \Throwable $e): void;
}

// Return JobResult::success() on completion
// Return JobResult::skipped($reason) to stop WITHOUT triggering retry
// Throw any Throwable to trigger retry strategy

JobResult::success(['rows' => 142])
JobResult::skipped('Invoice already paid — email not needed')
```

---

## ENTITY / CASTING / HYDRATION SUPPORT (Project layer — `Project\Support\`)

DI-free, I/O-free entity-mapping helpers under `projects/Support/` — the
GDA-compliant decomposition of the legacy `__DEV__/Entity` Active Record (the fat
AR base was split across the layers it conflated; only the reusable casting /
mapping / entity-mechanics live here). Import nothing outside their own
namespace; safe from any layer.

| Namespace | Class | Role |
|---|---|---|
| `Project\Support\Casting` | `DataCaster` | Cast ONE field, either direction (`get`=DB→PHP, `set`=PHP→DB) |
| `Project\Support\Casting` | `TypeParser` | Type string → `{nullable, baseType, params}` |
| `Project\Support\Casting` | `CastInterface`/`BaseCast`/`CastException` | Cast contract + identity base + errors |
| `Project\Support\Casting\Casts` | 11 built-ins | `int integer float double string bool boolean int-bool csv array json object datetime timestamp` |
| `Project\Support\Hydration` | `DataConverter` | Map a whole DB row ⇄ object (Repository hydrator; pools casters by types-hash) |
| `Project\Support\Entity` | `Entity` (abstract) | Enterprise base for domain entities; `JsonSerializable`+`ArrayAccess`+`Stringable` |

Type grammar: `?`=nullable, `base[param,param]`=handler+params (e.g.
`?json[array]`, `datetime[ms]`). `bool` casts on READ only — use `int-bool` when
the column stores `0/1` and the WRITE must emit an int. Custom casts via the
`castHandlers` arg / entity `$customCasters` (implement `CastInterface`).

`Entity` base provides: bidirectional `$casts`; **mass-assignment guard, secure
by default** (`$guarded=['*']`, `fill()` honours `$fillable`, `forceFill()`
bypasses); `$hidden`/`$visible` (+ runtime `makeHidden/makeVisible`);
`__debugInfo()` redacts `$hidden` as `********`; `$appends`; `$dates`/`$dateFormat`;
typed getters `getString/getInt/getFloat/getBool/getArray/getDate`; change
tracking `isDirty/isClean/wasChanged/getDirty/getChanges/getOriginal/syncOriginal`;
domain-event buffer `recordEvent()`+`releaseEvents()` (Service flushes in-tx;
entity never dispatches); `seal()` (mutation throws); hydrator seam
`static reconstitute(array)`+`toRawArray()`; `getKey/exists/is/isNot`, `make()`,
`replicate()` (drops PK).

Repository pattern (NOT Active Record):
`$e = Entity::reconstitute($row)` (or `DataConverter::reconstruct`) →
`$db->upsert($table, $e->toRawArray(onlyChanged:true), ['id'])`.

Guides: `projects/Support/Casting/README.md`, `projects/Support/Entity/README.md`.

---

## WHAT COPILOT MUST NEVER GENERATE FOR THIS PROJECT

```
✗ Use of Eloquent, Active Record, or any ORM
✗ Laravel facades, service providers, artisan patterns
✗ Symfony bundles, console components, event dispatcher
✗ Static route definitions in PHP — plugin routes in module.json, project routes in proj.json/withRoutes()
✗ Relying on plugin load order for route/view resolution — it is deterministic (project-over-plugin)
✗ Letting a plugin override a project route/view implicitly — only via an explicit lower view `priority`
✗ Two plugins claiming the same route or the same unnamespaced view name — boot fails / use `namespace::view`
✗ Env vars read in modules without being declared in module.json config[]
✗ Business logic in Kernel classes — kernel knows nothing about any domain
✗ Integration events dispatched inside a try{} block — dispatch AFTER commit
✗ Missing collector->discard() in a catch block
✗ getenv() in first-party module/plugin/bootstrap code — use the env() helper ($_ENV is the source of truth; putenv() is not called)
✗ Relying on putenv()/process env for .env values — LoadEnvironment injects $_ENV/$_SERVER only by default
✗ Vendor exceptions (Stripe, Guzzle, PDO) escaping a Gateway or Repository
✗ Security logic in the Application layer — it belongs in SecurityGateway layers
✗ Module code importing another module's internal class — use published contract
✗ float for money values — use Money value object with integer cents
✗ Config vars or event declarations in PHP — they belong in module.json (project routes excepted: proj.json/withRoutes())
✗ save()/delete()/find()/getRepo_() on a Project\Support\Entity\Entity — persistence is the Repository's job (DatabasePort)
✗ Calling app()/kernel()/config() or querying the DB from inside an entity — entities never do I/O (no magic __get DB fallback)
✗ float/getenv-style ad-hoc casting in a repository — declare $casts/types and run rows through DataCaster/DataConverter
✗ reconstruct() relying on reflection to write private props — give the entity a static reconstitute()/toRawArray()
✗ strict:false as a substitute for a nullable column — mark the type ?type instead
✗ throw inside a catch without rollbackTransaction() first
✗ hash comparison with === for tokens — use hash_equals() always
✗ Static properties in request-scoped classes — they leak between requests in Swoole
✗ CoreContainer::getInstance() or ::setInstance() — both throw LogicException (disabled)
✗ ModuleContainer::getInstance() or ::setInstance() — both throw LogicException (disabled)
✗ Calling CoreContainer::bind/singleton/extend after the kernel materializes (first entry-point call) — container is frozen
✗ Resolving an internal binding from outside its owning module — use makeInScope() or published contract
✗ Mutating ModuleContainer without calling reset() at end-of-request in Swoole workers
✗ CommandContract, Arguments, or Output from Cli/ — all @deprecated; extend AbstractCommand
✗ InputInterface / OutputInterface inside AbstractCommand::handle() — use $this->argument(), $this->option(), $this->info() etc.
✗ $output->writeln('<info>...') — use $this->info() / $this->success() / $this->warning() / $this->error()
✗ Two ProgressBar instances active simultaneously — cursor interference; use a single instance
✗ Symfony Console Command as a base class for module commands — AbstractCommand is standalone
✗ Laravel/Doctrine/Symfony migrations — ONLY use LetMigrate in this project
✗ Eloquent models, Doctrine entities, or ORM migrations — LetMigrate is framework-agnostic
✗ Routes in migrations or business logic — migrations ONLY define schema
✗ float for money in migrations — use decimal(precision, scale) with proper precision/scale
✗ Migrations without matching down() rollback methods
✗ Hardcoding database table names in migrations — use string literals only
✗ ON UPDATE CURRENT_TIMESTAMP on PostgreSQL — LetMigrate auto-creates BEFORE UPDATE triggers
✗ onUpdateCurrentTimestamp() on non-timestamp columns — only for DATE/DATETIME/TIMESTAMP types
✗ --seed flag without wiring SeederRunner to MigrateRefreshCommand
✗ Mutating migration files after applied — create a NEW migration instead
✗ Pretend mode in production — only for CI previews to test compiled SQL
```