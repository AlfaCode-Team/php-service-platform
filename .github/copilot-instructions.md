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
        new CsrfTokenLayer(exemptPaths: config('security.csrf_exempt')),
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
}

interface MailPort  { public function send(string|array $to, string $subject, string $view, array $data = []): void; }
interface SmsPort   { public function send(string $to, string $message): void; }
interface StoragePort {
    public function store(string $contents, string $filename, string $path = ''): string;
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;
    public function delete(string $path): bool;
}

interface HttpClientPort {   // outbound HTTP for Gateways — adapter: plugins/HttpClient (cURL)
    public function request(string $method, string $url, array $options = []): HttpClientResponse;
    public function get(string $url, array $query = []): HttpClientResponse;   // + post/put/patch/delete
}

interface SessionPort {      // request-scoped — adapter: plugins/Session (essential module)
    public function start(?string $id = null): void;
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value): void;
    public function flash(string $key, mixed $value): void;   // + pull/push/has/forget/flush/all
    public function token(): string;                          // CSRF
    public function regenerate(): void; public function invalidate(): void;
    public function shouldPersist(): bool; public function save(): void;  // lazy persistence
}
```

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
| Storage | `storage.local` | `StoragePort` (local + S3) | on-demand |
| View | `view.rendering` | `ViewRendererContract` (PHP templates: layouts, sections, decorators; project-first cascade + `ns::view`) | on-demand |
| HttpClient | `http.client` | `HttpClientPort` (cURL) | on-demand |
| Session / Cookie / RedisCache | session/cookies/cache | `SessionPort` / `CookieJar` / `CachePort`+`QueuePort` | essential |
| SecurityFilters | `http.security_filters` | global hooks: CORS, SecureHeaders. Route-filter aliases: `auth`, `throttle`, `hmac`, `shield` | hooked + filters |
| Pageflow | `http.pageflow` | `PageflowResponder` (SPA bridge) | on-demand |

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

## Base controllers + RequestAware

Optional base classes in `projects/Http/Controllers/` (namespace `Project\`; project layer,
NOT kernel — views/cookies are plugin concerns):
- `ApiController` — JSON helpers (`ok`, `created`, `noContent`, `paginated`, `okOrNotFound`,
  `notFound`, `forbidden`, `unprocessable`, `identity`); pure kernel types.
- `ViewController` — HTML helpers (`view`, `viewNotFound`, `redirect`, `back`); injects
  `ViewRendererContract`.
- Both `use InteractsWithCookies` (wraps every public `CookieJar` method, no `$request` arg).

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