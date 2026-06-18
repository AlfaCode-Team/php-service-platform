# AlfacodeTeam PhpServicePlatform Framework — Claude AI Master Context

> This file is read automatically by Claude Code (`claude` command).
> Upload to Claude.ai Projects as the primary knowledge file.
> This covers the complete AlfacodeTeam PhpServicePlatform Framework — architecture, patterns, rules, and implementation.

---

## WHAT THIS PROJECT IS

**Framework:** AlfacodeTeam PhpServicePlatform — a PHP 8.2+ framework built on the **Gated Demand Architecture (GDA)** pattern.
**Package:**   `alfacode-team/php-service-platform`
**Namespace:** `AlfacodeTeam\PhpServicePlatform\Kernel\`
**PHP:**       8.2+ (uses readonly classes, enums, named arguments, fibers)

This is NOT Laravel. This is NOT Symfony. This is NOT Slim.
Do NOT suggest those frameworks' patterns, classes, or conventions.
Every suggestion must follow the AlfacodeTeam PhpServicePlatform rules in this file.

---

## NATIVE DISTRIBUTION (NO COMPOSER REQUIRED FOR END USERS)

This repository supports OS-native distribution through GitHub Releases.

- Release workflow: `.github/workflows/release.yml`
- Trigger: push a tag matching `v*` (example: `v1.0.0`)
- Artifacts:
    - Linux: `psp-kernel_<version>_amd64.deb`
    - Windows: `psp-kernel-<version>-windows-x86_64.zip`
    - macOS: `psp-kernel-<version>-macos-universal.tar.gz`

Launcher/config binaries are built from `tools/psp-launcher-zig/`.
Use pinned Zig version from `tools/psp-launcher-zig/.zig-version`.

---

## THE CORE PHILOSOPHY

| Principle | Meaning |
|---|---|
| Security before everything | SecurityGateway runs before any module loads. Denied = zero module cost. |
| Load only what is needed | Only modules required for this specific request are loaded. |
| One module, one domain | Every module owns exactly one business domain. No exceptions. |
| Isolation by default | Modules cannot access each other's internals. Scoped containers enforce this at runtime. |
| Infrastructure independence | Kernel defines port interfaces. Project provides implementations. |
| Explicit over implicit | Everything declared in module.json. Nothing auto-discovered at runtime. |

---

## THE THREE WORLDS

```
┌─────────────────────────────────────────────────┐
│  PROJECT LAYER  (wiring only — no business logic)│
│  ┌─────────────────────────────────────────────┐ │
│  │  MODULE LAYER  (bounded domain contexts)    │ │
│  │  ┌───────────────────────────────────────┐  │ │
│  │  │  KERNEL  (boot, security, loading,    │  │ │
│  │  │  pipelines, DI, events, ports)        │  │ │
│  │  └───────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

- **Kernel** — knows nothing about modules or business domains. Changes rarely.
- **Module** — knows nothing about the project. Wires to kernel through contracts only.
- **Project** — knows everything, contains no business logic. Wiring only.

---

## THE FIVE ACCESS RULES — ABSOLUTE — RUNTIME ENFORCED

```
Controller  →  Service   (published contract interface ONLY)
Service     →  Repository  AND  Gateway  (ONLY layer calling both)
Repository  →  DatabasePort ONLY         (no HTTP, no vendor SDK)
Gateway     →  Vendor SDK ONLY           (no DB, no services)
Domain      →  NOTHING EXTERNAL          (zero imports outside Domain/)
```

`ModuleContainer::bindInternal()` enforces these at runtime.
Violations throw `ScopeViolationException`.

---

## KERNEL FOLDER STRUCTURE

```
sentinel/kernel/
├── composer.json
└── src/
    ├── Kernel.php                         ← fluent builder entry point
    ├── Contracts/
    │   └── ModuleContract.php             ← solves, requires, exposes, register, boot
    │
    ├── Boot/
    │   ├── BootPipeline.php               ← 10 stages, fail-fast
    │   └── Stages/
    │       ├── ValidateConfigStage           ← 1. all env vars present + typed
    │       ├── DetectConflictsStage          ← 2. no shared solves() domain
    │       ├── DetectCyclesStage             ← 3. no circular requires[]
    │       ├── CompileServiceManifestStage   ← 4. services[] → service-manifest.php (+ synthetic __project__ scope)
    │       ├── CompileRouteManifestStage     ← 5. routes[] → route-manifest.php (plugin + project routes; project OVERRIDES)
    │       ├── CompileViewManifestStage      ← 6. views[] → view-manifest.php (project-first cascade + namespaces)
    │       ├── CompileJobManifestStage       ← 7. jobs → job-manifest.php (with solves domain)
    │       ├── CompileCommandManifestStage   ← 8. commands → command-manifest.php
    │       ├── RegisterPortsStage            ← 9. verify port bindings
    │       └── BindSecurityStage             ← 10. verify security layers
    │
    ├── Security/
    │   ├── SecurityGateway.php            ← runs before ANY module loads
    │   ├── SecurityVerdict.php            ← allow(Identity) | deny(code, reason)
    │   ├── Identity.php                   ← immutable: userId, tenantId, roles, permissions
    │   ├── Contracts/SecurityLayerContract.php
    │   └── Layers/
    │       ├── FirewallLayer.php          ← IP blocklist/allowlist (cheapest — runs first)
    │       ├── RateLimiterLayer.php       ← sliding window counter via CachePort
    │       └── CsrfTokenLayer.php         ← stateless double-submit cookie CSRF check
    │
    │   (JWT / API-key / session authentication is provided by a project's
    │    Auth module — the kernel does not ship a token validator.)
    │
    ├── Loading/
    │   ├── DependencyGraphCalculator.php  ← DFS over service-manifest.php
    │   ├── DependencyGraph.php            ← ordered module list
    │   └── OnDemandLoader.php             ← register() then boot() per module
    │
    ├── Container/
    │   ├── CoreContainer.php              ← app-lifetime: ports + kernel services
    │   └── ModuleContainer.php            ← request-scoped: scope isolation enforced
    │
    ├── Pipelines/
    │   ├── Http/
    │   │   ├── HttpPipeline.php           ← assembles stages + hooks, runs handle(Request)
    │   │   ├── Contracts/HttpStageContract.php
    │   │   └── Stages/
    │   │       ├── CorrelationIdStage     ← always first, X-Correlation-ID
    │   │       ├── SecurityStage          ← runs SecurityGateway
    │   │       ├── ResolveStage           ← route-manifest.php → service name
    │   │       ├── LoadStage              ← dep graph → OnDemandLoader
    │   │       ├── ExecuteStage           ← service contract → DTO → handler
    │   │       └── ErrorStage             ← wraps all, catches Throwable
    │   │
    │   ├── Cli/
    │   │   ├── CliPipeline.php            ← wraps php-io-cli CLIApplication
    │   │   ├── Arguments.php              ← @deprecated — use AbstractCommand::$input
    │   │   ├── Output.php                 ← @deprecated — use AbstractCommand::$output
    │   │   ├── Contracts/CommandContract.php ← @deprecated — extend AbstractCommand instead
    │   │   └── Stages/
    │   │       ├── CliCorrelationIdStage
    │   │       ├── AuthenticateCommandStage
    │   │       ├── ResolveCommandStage
    │   │       ├── LoadCommandStage
    │   │       ├── ValidateArgsStage
    │   │       └── ExecuteCommandStage
    │   │
    │   └── Worker/
    │       ├── WorkerPipeline.php
    │       ├── WorkerLoop.php             ← builds ModuleContainer per job (DI, TransactionManager, events)
    │       ├── JobPayload.php
    │       ├── JobResult.php
    │       ├── Contracts/JobContract.php
    │       ├── Retry/
    │       │   ├── RetryStrategyContract.php
    │       │   ├── ExponentialRetryStrategy.php  ← base * 2^(attempt-1), capped, optional jitter
    │       │   ├── LinearRetryStrategy.php       ← base * attempt, capped
    │       │   └── FixedRetryStrategy.php        ← constant delay
    │       └── Stages/
    │           ├── DequeueStage
    │           ├── ValidateSignatureStage
    │           ├── ValidatePayloadStage
    │           ├── OnDemandLoaderStage
    │           ├── ExecuteJobStage
    │           └── AcknowledgeStage
    │
    ├── Events/
    │   ├── EventBus.php                   ← dispatches to subscribers, isolated failures
    │   ├── DomainEventCollector.php       ← buffer in-tx, discard on rollback
    │   └── Contracts/
    │       ├── DomainEventContract.php
    │       ├── IntegrationEventContract.php
    │       └── EventListenerContract.php
    │
    ├── Ports/
    │   ├── DatabasePort.php
    │   ├── CachePort.php
    │   ├── QueuePort.php
    │   ├── MailPort.php
    │   ├── SmsPort.php
    │   └── StoragePort.php
    │
    ├── Http/                                   ← HTTP engine: Symfony HttpFoundation (see note below)
    │   ├── Request.php                         ← final, immutable; extends Symfony Request
    │   ├── Response.php                        ← final; single response type (json/html/stream/download/redirect)
    │   ├── UploadedFile.php                    ← extends Symfony UploadedFile (FPM-safe + Swoole via fromSwoole)
    │   ├── Uri.php                             ← immutable PSR-7 UriInterface       → Request::uri()
    │   ├── SiteUri.php                         ← absolute-URL generator (host-aware) → Request::site()
    │   ├── Negotiate.php                       ← Accept-* content negotiation        → Request::negotiate()
    │   ├── UserAgent.php / Method.php          ← parsed UA value object; HTTP method enum
    │   └── Concerns/ManagesResponse.php        ← shared immutable response accessors/mutators
    │       (outbound HTTP → HttpClientPort + plugins/HttpClient; CORS → plugins/SecurityFilters)
    │
    ├── Database/
    │   └── TransactionManager.php
    │
    ├── Error/
    │   ├── ErrorPipeline.php
    │   ├── ErrorContext.php
    │   ├── ErrorClassifier.php
    │   └── Notifiers/
    │       ├── SlackNotifier.php
    │       ├── MailNotifier.php
    │       ├── DatabaseErrorLogger.php
    │       └── FileNotifier.php          ← ALWAYS runs — guaranteed fallback
    │
    └── Exceptions/
        ├── FrameworkException.php        ← abstract base (layer, context, previous)
        ├── SecurityException.php         ← HTTP 401/403   severity: warning
        ├── DomainException.php           ← HTTP 422       severity: info
        ├── ServiceException.php          ← HTTP 422/500   severity: warning
        ├── RepositoryException.php       ← HTTP 500       severity: critical
        ├── GatewayException.php          ← HTTP 502       severity: critical
        ├── KernelException.php           ← HTTP 500       severity: critical
        ├── BootException.php
        ├── BootFailureException.php
        ├── ScopeViolationException.php
        ├── CircularDependencyException.php
        ├── ValidationException.php       ← HTTP 422 + field-level errors
        └── OptimisticLockException.php
```

---

## HTTP ENGINE — SYMFONY HTTPFOUNDATION (TRANSITIONAL)

> **Status:** the kernel HTTP layer (`src/Kernel/Http/`) is currently built ON TOP of
> `symfony/http-foundation` (+ `symfony/mime`). `Request` extends Symfony's `Request`,
> `Response` extends Symfony's `Response`. This is a **deliberate, temporary** choice to
> get a battle-tested parser/emitter quickly.
>
> **Future direction:** the kernel is intended to become **dependency-free** — Request /
> Response / UploadedFile will be reimplemented as pure value objects with no vendor
> coupling (the original GDA intent). Treat the Symfony dependency as an implementation
> detail behind the kernel's own API, NOT as something modules may rely on directly.

Rules that still hold REGARDLESS of the engine (do not regress these when the kernel goes
dependency-free):

- **`Request` is immutable.** Every mutator (`withHeader`, `withAttribute`, `withIdentity`,
  `withContainer`, `merge`, `replace`) returns a NEW instance. `__clone()` deep-clones the
  parameter bags so clones are fully isolated — required for OpenSwoole/coroutine safety.
- **One `Response` type.** Controllers and pipeline stages are typed `: Response`. Use the
  named constructors (`json`, `html`, `text`, `empty`, `notFound`, `unauthorized`,
  `forbidden`, `unprocessable`, `redirect`, `stream`, `download`, `file`, `jsonp`, …). Do
  NOT introduce sibling response classes that extend Symfony types directly — they would
  not be `instanceof` the kernel `Response` and would break the contract.
- **No hidden globals** in the HTTP layer (no `config()` / `kernel()` / `collect()`). Any
  view/render dependency must be INJECTED, never resolved from a global.
- **No outbound HTTP in the kernel.** Outbound calls go through `HttpClientPort` with the
  adapter in `plugins/HttpClient` — never a concrete client inside `src/Kernel/Http/`.
- **No CORS in the kernel.** CORS is a cross-cutting security stage in
  `plugins/SecurityFilters` (CorsStage), not a kernel class.
- **DomainContext rides on the Request** via `withAttribute('domain', …)`; it is never bound
  into a container.

Modules and consumers should depend ONLY on the kernel's own method surface
(`$request->method()/path()/input()/header()/attribute()/…`, `Response::json()/…`), never on
Symfony classes — so the eventual switch to a dependency-free kernel is a non-breaking change.

---

## HTTP LAYER — USAGE

### Reading the Request (immutable)

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

$request->method();                 // 'POST'            (always upper-case)
$request->path();                   // '/api/invoices'   (leading slash, no query)
$request->isMethod('post');         // true
$request->isSecure();               // bool (honours X-Forwarded-Proto)

// Input — body + query merged; JSON bodies decoded automatically.
$request->input('title', 'Untitled');
$request->all();                    // ['title' => '…', 'page' => '2', …]
$request->only(['title', 'amount']);
$request->except(['_token']);
$request->has('title');             // key present (even if empty)
$request->filled('title');          // present AND not '' / []
$request->boolean('active');        // "1"/"true"/"on"/"yes" → true
$request->integer('page');  $request->float('rate');  $request->string('q');
$request->query('page');    $request->queryAll();     $request->post('title');

// Headers / cookies / files / auth
$request->header('Accept');         // case-insensitive, ?string
$request->cookie('session_id');
$request->bearerToken();            // from Authorization: Bearer …
$file = $request->file('avatar');   // ?UploadedFile  (see below)

// Routing / negotiation helpers
$request->segments();               // ['api', 'invoices']
$request->segment(1);               // 'api'
$request->is('api/*');              // wildcard path match
$request->expectsJson();            // AJAX / Accept / JSON body
$request->accepts(['application/json', 'text/html']);

// Identity + per-request container + DomainContext (set by the pipeline)
$request->identity();               // ?Identity
$request->container();              // ?ModuleContainer
$request->attribute('domain');      // ?DomainContext  (never via a global)
```

Immutable mutators — every one returns a NEW request, original untouched:

```php
$request = $request->withAttribute('locale', 'fr');
$request = $request->withHeader('X-Trace', $id);
$request = $request->merge(['source' => 'import']);   // add to active input
$request = $request->replace(['only' => 'these']);    // replace active input
// withIdentity() / withContainer() are used by the pipeline, not controllers.
```

### Building a Response (one type, immutable)

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;

Response::json($dto->toArray(), 201);
Response::created($dto->toArray(), location: "/api/invoices/{$id}");
Response::noContent();                       // 204
Response::text('pong');   Response::html($markup);
Response::redirect('/login');   Response::back($request->header('referer'));

// Error envelopes — body shape { "error": { "code", "message"[, "fields"] } }
Response::notFound();        Response::unauthorized();   Response::forbidden();
Response::unprocessable(['email' => 'Required.']);       // 422 + fields
Response::tooManyRequests(retryAfter: 30);
Response::serverError();

// Streaming / files (work on BOTH PHP-FPM and OpenSwoole)
Response::stream(fn() => print(generateCsv()));          // chunked, no full buffer
Response::download($path, 'report.pdf');                 // sendfile under Swoole
Response::file($path);                                   // inline (in-browser)
Response::jsonp('cb', $data);

// Immutable chaining
return Response::json($data)
    ->withHeader('Cache-Control', 'no-store')
    ->withCookie('sid', $token, maxAge: 3600);           // secure+httpOnly defaults
```

Controllers stay ≤3 lines and are typed `: Response` — never return Symfony response
classes; they are not `instanceof` the kernel `Response` and break the contract.

### URL helpers — `Request::uri()` / `Request::site()`

```php
// Build a redirect target / canonical URL from the current request (PSR-7 Uri):
$login = (string) $request->uri()->withPath('/login')->withQuery('');

// Absolute URLs that must not hardcode the host (OAuth callbacks, email links,
// sitemaps) — host comes from the DomainResolver-validated request:
$callback = $request->site()->to('auth/callback');       // https://app.example.com/auth/callback
$reset    = $request->site()->to('password/reset', ['token' => $t]);
```

### Content negotiation — `Request::negotiate()`

```php
$locale = $request->negotiate()->language(['en', 'fr', 'ar']);   // i18n from Accept-Language
$type   = $request->negotiate()->media(['application/json', 'text/csv']);
$enc    = $request->negotiate()->encoding(['br', 'gzip']);
```

### Uploads — FPM-safe and Swoole-safe

```php
$file = $request->file('avatar');
if ($file !== null && $file->isValid()) {
    $name = $file->clientName();        // original filename
    $ext  = $file->extension();
    $file->move($dir, $generatedName);  // FPM: move_uploaded_file; Swoole: rename
}
```

`UploadedFile::createFromBase()` keeps the `is_uploaded_file()` safety check for real
PHP-FPM uploads; the Swoole adapter injects files via `UploadedFile::fromSwoole()` (test
mode, since the temp file was not created by PHP's multipart handler).

### Where each surface lives (do NOT put these in the kernel Http layer)

| Concern | Correct home |
|---|---|
| Outbound HTTP calls | `HttpClientPort` + `plugins/HttpClient` |
| CORS | `plugins/SecurityFilters` (CorsStage) |
| Sessions / cookies jar | `plugins/Session`, `plugins/Cookie` |
| Views / templating | injected renderer (project/plugin), never a global in Http |

---

## KERNEL.PHP — FLUENT BUILDER

```php
// app/bootstrap/base.php — shared base builder (NO ->build())
return Kernel::configure()

    ->withPorts([
        DatabasePort::class => new MySQLAdapter(config('database')),
        CachePort::class    => new RedisAdapter(config('cache')),
        QueuePort::class    => new RedisQueueAdapter(config('jobs')),
        MailPort::class     => new SendGridMailGateway(config('sendgrid')),
        SmsPort::class      => new TwilioSmsGateway(config('twilio')),
        StoragePort::class  => new S3StorageAdapter(config('storage')),
    ])

    ->withSecurity([
        new FirewallLayer(blocklist: config('security.blocklist')),            // 1st — cheapest
        new RateLimiterLayer(store: CachePort::class, limits: config('security.limits')), // 2nd
        new CsrfTokenLayer(                                                   // 3rd — stateless
            cookieName:   'XSRF-TOKEN',
            headerName:   'X-CSRF-Token',
            exemptPaths:  config('security.csrf_exempt'),  // e.g. ['/api/webhooks']
        ),
        // Token/JWT verification: provide via your AuthModule (Provider::boot()
        // registers a layer hook). The kernel intentionally ships no JWT code.
    ])

    ->withErrorPipeline(
        ErrorPipeline::notifiers([
            new SlackNotifier(env('SLACK_ERROR_WEBHOOK')),
            new MailNotifier(config('errors.mail_to')),
            new DatabaseErrorLogger(),
        ])
        ->fallback(new FileNotifier(logs_path('errors.log')))
        ->rules([
            'critical' => ['slack', 'mail', 'database', 'file'],
            'warning'  => ['database', 'file'],
            'info'     => ['file'],
        ])
    )

    ]);

// projects/admin/bootstrap/app.php — admin project bootstrap
/** @var Kernel $builder */
$builder = require __DIR__ . '/../../../app/bootstrap/base.php';

return $builder
    ->withProjectPath(dirname(__DIR__))
    ->withModules([
        AuthModule::class,
        InvoiceModule::class,
        PaymentModule::class,
    ])
    ->build();

// bootstrap/app.php is now a backward-compatible shim that delegates to
// projects/admin/bootstrap/app.php.

// Entry points (each materializes the kernel for its surface on first call):
$kernel->http()         // HttpPipeline::handle(Request): Response
$kernel->cli()          // CliPipeline::run(argv): int
$kernel->workerLoop()   // WorkerLoop::run(callable $puller, int $maxIterations = 0): void
$kernel->mode()         // ?RuntimeMode — surface materialized for, null until first entry point

// LAZY MATERIALIZATION — build() is compile-only.
//   build()            → runs BootPipeline (validate config + compile manifests). It
//                        does NOT construct pipelines, wire modules, or freeze the core.
//   first http()/cli()/workerLoop() call → materialize(RuntimeMode): constructs the
//                        pipelines, wires every module ONCE (Provider::boot), and freezes
//                        the core container. Runs at most once.
// Module boot() couples all three pipelines + event subscriptions in one call, so all
// three pipeline instances are constructed at materialize — but each is a cheap shell:
// HttpPipeline and WorkerLoop defer their manifest disk I/O until their OWN first run.
// Net effect: an HTTP-only process never reads the job manifest; a CLI process never
// reads the route manifest. ->container() also materializes (defaults to RuntimeMode::Cli).

// HTTP entry points (app/public_html/index.php, app/api/server.php) pick the
// active project from the Host header via App\Bootstrap\Domain\DomainResolver,
// falling back to HKM_PROJECT env var, then 'admin'. CLI/worker entries stay
// env-driven. See the "DOMAIN RESOLUTION" section below for the full pattern.
```

Builder semantics (inheritance-safe):
- `withPorts([...])` merges with previous bindings (later wins on key collision).
- `withSecurity([...])` appends layers to existing ones.
- `withModules([...])` appends and de-duplicates modules preserving insertion order.

---

## DOMAIN RESOLUTION (PROJECT LAYER — NOT KERNEL)

Domain resolution maps an incoming Host header to a `DomainContext` (project name +
face + features). It lives entirely under `app/Bootstrap/Domain/` so the kernel
remains domain-agnostic.

### Files

| Path | Role |
|---|---|
| `app/Bootstrap/Domain/DomainType.php` | Backed enum: `Admin`='admin', `Api`='api', `Project`='project', `Public`='public' |
| `app/Bootstrap/Domain/DomainContext.php` | `final readonly` value object: `name`, `projectPath`, `type`, `host`, `features[]` |
| `app/Bootstrap/Domain/DomainResolver.php` | Pure static `resolve(basePath, host): ?DomainContext` |
| `app/Bootstrap/EntryHelpers.php` | `resolveDomain`, `projectFromContext`, `bootstrapPathFor` shared by entry points |
| `projects/platform.json` | Subdomain registry — which subdomains are admin / api faces |
| `projects/projects.json` | Project → domains[] mapping |
| `projects/<name>/proj.json` | Optional — surfaces `features[]` on the resolved context |

Per-project filesystem roots:

| Path | Role |
|---|---|
| `projects/<name>/var/` | Ephemeral runtime (logs/cache/manifests/tmp/locks) |
| `projects/<name>/userdata/` | Persisted tenant data (uploads/reports/exports) |
| `projects/<name>/config/` | Project-specific configuration files |
| `projects/<name>/database/` | Project migration/seed/factory files |
| `projects/<name>/src/` | Project-only PHP logic — namespace `Projects\<Name>\` |
| `projects/<name>/app/` | Project-local entry points (standalone deploy) |

#### Project `src/` — project-only logic (NOT a plugin, NOT the kernel)

Each project may own a `src/` for logic that is specific to that project and not
reusable: wiring glue, project-only services/listeners/value objects, console
commands. It autoloads PSR-4 under its OWN root namespace:

```
"Projects\\Admin\\": "projects/admin/src/"      // composer.json autoload.psr-4
```

So `projects/admin/src/Support/Clock.php` is `Projects\Admin\Support\Clock`. Add
one PSR-4 line per project. Decision matrix:

| Where | Use for |
|---|---|
| `src/` (root) | Kernel internals — never project/business logic |
| `plugins/` | Reusable business modules (full GDA, `Plugins\` namespace) |
| `projects/<name>/src/` | Logic for ONE project only — not reusable elsewhere |

Plugin routes live in a plugin's `module.json`. A project may ALSO declare its
own routes in `proj.json` (or via `Kernel::withRoutes()`) — see "RESOURCE
RESOLUTION" below. Never define routes in PHP files. Wire anything in project
`src/` through that project's `bootstrap/app.php`.

#### Project `app/` — project-local entry points (standalone deploy)

Each project may own an `app/` mirroring the four shared entry points
(`public_html/index.php`, `api/server.php`, `cli/run.php`, `worker/run.php`).
Use these when deploying ONE project standalone — set the webserver docroot to
`projects/<name>/app/public_html`. They are project-agnostic templates: each
self-locates the root and its owning project name from its own path, so they can
be copied verbatim into any new `projects/<name>/app/`.

Difference from the SHARED `/app` entries:

| Shared `/app/*` (dispatcher) | Project `projects/<name>/app/*` |
|---|---|
| Picks project via Host → `HKM_PROJECT` → `admin` | Project PINNED to the folder it lives in |
| Multi-tenant from one docroot | One docroot per project (standalone) |
| `require EntryHelpers::bootstrapPathFor(...)` | `require $projectPath . '/bootstrap/app.php'` |

Both still attach a `DomainContext` to the Request (admin/api face) when a Host
is present, and both load env + install `ErrorGuard` before requiring the kernel.

Path resolution fallback contract:

- When `withProjectPath()` is set in `projects/<name>/bootstrap/app.php`, `Paths::var()/logs()/cache()/userdata()/config()` resolve under that project root.
- When no project path is set, they fall back to base roots (`<base>/var`, `<base>/userdata`, `<base>/config`).

### Resolution algorithm (two-pass)

```
1. Normalise host: lowercase, strip port, strip trailing dot, unwrap IPv6 brackets
2. Determine face from subdomain via platform.json registry (admin / api / project)
3. PASS 1 — exact host match against projects.json domains[]
4. PASS 2 — '.domain' suffix match against projects.json domains[]
5. If neither matched but subdomain is admin/api → platform-only context
6. Otherwise return null (entry point falls back to HKM_PROJECT then 'admin')
```

Exact match wins over suffix match so a project that registers `app.example.com`
directly beats one that registers `example.com` when resolving `app.example.com`.

### Wiring (already applied)

```php
// app/public_html/index.php — FPM entry
$rootPath = dirname(__DIR__, 2);
$domain   = EntryHelpers::resolveDomain($rootPath, $_SERVER['HTTP_HOST'] ?? null);
$project  = EntryHelpers::projectFromContext($domain);
$kernel   = require EntryHelpers::bootstrapPathFor($rootPath, $project);

$request = Request::capture();
if ($domain !== null) {
    $request = $request->withAttribute('domain', $domain);
}
$kernel->http()->handle($request)->send();

// app/api/server.php — OpenSwoole entry uses the same pattern inside the
// request handler. The kernel is still booted once per worker for the
// env-selected project; DomainContext rides on the per-request Request.
```

### Reading the context

Modules receive the context through the immutable `Request` — never via the
container (no static singletons, no coroutine leakage):

```php
/** @var ?App\Bootstrap\Domain\DomainContext $ctx */
$ctx = $request->attribute('domain');

if ($ctx?->isApi())       { /* api face */ }
if ($ctx?->isPlatformOnly()) { /* no project matched, but admin/api subdomain */ }
```

### Swoole / coroutine safety

- `DomainContext` is NEVER bound into `CoreContainer` or `ModuleContainer`.
  It travels on the immutable `Request`, so coroutines sharing a worker cannot
  bleed it between in-flight requests.
- `DomainResolver::$cache` is a worker-level static keyed by `basePath`. It is
  populated once per worker per basePath and never mutated on the hot path.
  Multi-tenant test harnesses or side-by-side deploys with different basePaths
  cannot poison each other's registry.
- Registry files are deploy-time artifacts. A redeploy spawns new workers,
  which rebuild the cache. There is no runtime invalidation API on the hot path
  (`DomainResolver::flushCache()` exists only for tests).
- Project names from JSON are validated against `/^[a-zA-Z0-9_\-]+$/` before
  being concatenated into a filesystem path — defends against traversal in
  `bootstrapPathFor()` and `loadProjFeatures()`.

### What MUST NOT happen

```
✗ Binding DomainContext into CoreContainer or ModuleContainer
✗ Reading $_SERVER directly inside a module to get the host — use $request->attribute('domain')
✗ Hard-coding project names in modules — read them from DomainContext->name
✗ Mutating the resolver cache at runtime in production (no hot-reload of platform.json)
✗ Putting business logic in DomainResolver — it stays in the Project layer, no kernel calls
```

---

## ENVIRONMENT & CONFIG LOADING (PROJECT LAYER — NOT KERNEL)

Environment loading and the pre-kernel error net live under `app/Bootstrap/Environment/`
so the kernel stays environment-agnostic. Every entry point loads env + installs the
guard BEFORE requiring the kernel bootstrap.

### Files

| Class | Role |
|---|---|
| `App\Bootstrap\Environment\LoadEnvironment` | `.env` cascade loader: `load($rootPath, ?DomainContext, ?array $argv)` |
| `App\Bootstrap\Environment\ErrorGuard`      | Pre-kernel + fatal safety net: `install(?string $logFile, bool $registerHandlers = true)` |
| `src/Kernel/Error/DebugPageRenderer`        | Dependency-free developer debug page (HTML + CLI). Debug-only. Shared by ErrorGuard AND the kernel ErrorStage. |

### The `.env` three-tier cascade

```
TIER 1  base     {root}/.env, then {root}/.env.{APP_ENV|--env}
TIER 2  domain   {root}/.env.{sld}, .env.{sub}, .env.{sub}.{sld}   (parsed from host)
TIER 3  project  {projectPath}/.env (+ the same domain cascade)
```

Each file is optional; a later file overrides an earlier key (mutable cascade).
Values already present in the REAL process environment are never clobbered — true
OS/server config always wins. Self-contained parser (no `vlucas/phpdotenv` dependency:
native distributions ship without `vendor/`).

### `env()` is the canonical reader — NEVER `getenv()` in first-party code

`LoadEnvironment` injects values into `$_ENV` and `$_SERVER` ONLY. It does **not**
call `putenv()` by default — `putenv()` was ~98% of injection cost (~1.7µs/var) and
is coroutine-unsafe under OpenSwoole. Therefore `getenv()` does NOT see `.env` values.
Read config through the global `env($key, $default)` helper
(`src/Kernel/Support/helpers.php`), which reads `$_ENV`/`$_SERVER` first and falls back
to `getenv()` for genuine OS variables.

```php
$secret = env('JWT_SECRET', '');        // ✅ correct
$secret = getenv('JWT_SECRET') ?: '';   // ✗ empty for any .env-provided key
```

Only enable process-env mirroring for a third-party SDK that reads the OS env directly
(AWS/Vault): `LoadEnvironment::useProcessEnv(true)`.

### Compiled cache (opt-in, FPM only)

Off by default. Enable in production with `ENV_CACHE=1` (or `LoadEnvironment::useCache(true)`):
writes a compiled `var/cache/env.<scope>.php` that opcache serves; stat-invalidated by
mtime+size of every examined file. Under OpenSwoole env loads once per worker, so the
cache is irrelevant there. Left off in dev because mtime granularity (1s) is unsafe for a
rapidly-edited `.env`.

### Entry-point order (all four entry points)

```php
require vendor/autoload.php;
$domain = EntryHelpers::resolveDomain($rootPath, $host);   // HTTP only (null in CLI/worker)
LoadEnvironment::load($rootPath, $domain, $argv);          // 1. env FIRST
ErrorGuard::install($logRoot . '/var/logs/errors.log');    // 2. error net (ini-only on Swoole)
$kernel = require EntryHelpers::bootstrapPathFor(...);      // 3. THEN the kernel
```

### TWO ERROR LAYERS (nested nets)

```
ErrorGuard (SAPI-level, pre-kernel)  ── outer net ── pre-kernel throws + PHP fatals/parse/OOM
   └── Kernel ErrorStage/ErrorPipeline ── inner net ── Throwables inside a running pipeline
```

- **ErrorStage/ErrorPipeline** (kernel): classify → notifiers (Slack/Mail/DB/File); generic
  JSON to clients, or the `DebugPageRenderer` HTML page for a browser when `APP_DEBUG=true`.
- **ErrorGuard** (project): forces `display_errors=off` in production; renders a generic
  secret-free 500, or the same debug page in debug. Catches what the kernel never can —
  the APP_KEY guard throw, parse errors, fatals.
- Both write to ONE log: `{project}/var/logs/errors.log` (FileNotifier + ErrorGuard's JSON
  line tagged `source=error_guard`). ErrorGuard never calls the ErrorPipeline (no global
  singletons) — the connection is the shared log file only.
- The debug page renders ONLY for real browser navigations. API/AJAX/JSON callers
  (`Request::expectsJson()` in the kernel; `$_SERVER` headers + `/api` prefix in the guard)
  always receive JSON. The debug page NEVER renders in production.

### What MUST NOT happen (environment & errors)

```
✗ getenv() for a .env value in any module/plugin/bootstrap — use env()
✗ Relying on putenv()/process env for .env values (not set by default)
✗ Loading env or wiring the error net INSIDE the kernel — both are Project layer
✗ Rendering DebugPageRenderer without an APP_DEBUG gate — it exposes source + traces
✗ Returning the HTML debug page to an API/AJAX/JSON client — they get JSON
```

---

## INTERNAL PACKAGES

The kernel uses four first-party packages that live in `modules/` and are autoloaded as
path repositories via `composer.json`. Do not replace them with Laravel/Symfony equivalents.

| Package | Namespace | Role |
|---|---|---|
| `phpshots/bind-it` | `PHPShots\Common\` | DI container engine — reflection autowiring, PSR-11, contextual bindings |
| `phpshots/common-type-alias` | `PHPShots\Common\TypeAlias\` | Type alias management — dependency of bind-it |
| `alfacode-team/php-io-cli` | `AlfacodeTeam\PhpIoCli\` | Standalone CLI application runtime — reactive terminal components, structured command execution, unified I/O layer |
| `alfacode-team/let-migrate` | `AlfaCode\LetMigrate\` | Enterprise-grade database migration engine — multi-database support, fluent Schema API, seeders, full-featured CLI |

**bind-it** (`modules/bind-it/`) — `Container` is the base class for both `CoreContainer` and
`ModuleContainer`. It provides `bind()`, `singleton()`, `instance()`, `make()`, contextual
bindings, extenders, `resolving()` callbacks, and `rebinding()`. GDA scope rules are layered on
top inside the kernel wrappers — bind-it itself is not modified.

**php-io-cli** (`modules/php-io-cli/`) — `CliPipeline` wraps `CLIApplication`. Module commands
extend `AbstractCommand` — a **standalone** class (not a Symfony Console wrapper). Register a
command with `$cli->command(MyCommand::class)` — a class-string; `CliPipeline` instantiates it
via `CoreContainer` (allowing port injection) or directly. Symfony Console is an optional dev
dependency used only by `ConsoleIO` and `BufferIO` as a non-TTY fallback; the core dispatcher
and `AbstractCommand` have zero Symfony dependency.

**let-migrate** (`modules/let-migrate/`) — Enterprise-grade, framework-agnostic database
migration engine with no framework dependencies (only requires PSR-3 logger + PDO). Supports
MySQL, PostgreSQL, SQLite, and SQL Server out of the box. Uses fluent `Blueprint` API to write
migrations once and compile to correct dialect DDL per database. Includes: per-driver migration
folders, batched runs & rollbacks, full transaction support, lifecycle events, schema introspection,
`make:migration` scaffolder, and seeder engine (`db:seed`). Extensible via `DriverRegistry` for custom databases.
Never use Laravel/Symfony migration classes — use LetMigrate's `MigrationInterface` directly.
See [Migration Guide](docs/ai-context/18_MIGRATIONS.md) for full API and patterns.

**php-io-cli component inventory** (all in `AlfacodeTeam\PhpIoCli\Components\`):

| Component | Type | Returns | Notes |
|---|---|---|---|
| `TextInput` | Interactive | `string` | Free-text, inline validation, placeholder, HOME/END nav |
| `Password` | Interactive | `string` | Masked input, TAB toggle plaintext, strength meter |
| `NumberInput` | Interactive | `int\|float` | Arrow-key stepping, min/max clamp, integer mode |
| `Confirm` | Interactive | `bool` | Yes/No button toggle |
| `Select` | Interactive | `string` | Fuzzy-filter single-selection, scroll window (8 items) |
| `MultiSelect` | Interactive | `string[]` | Spacebar-toggle checkbox list |
| `Autocomplete` | Interactive | `string` | Live fuzzy dropdown, TAB fill |
| `DatePicker` | Interactive | `DateTimeImmutable` | Calendar grid, week/month navigation |
| `RadioGroup` | Interactive | `string` | Radio-button group for ≤ 5 choices; columns layout; 1-9 digit shortcuts |
| `SliderInput` | Interactive | `int\|float` | Horizontal bar slider; `←→` step, `[]` jump 10%, HOME/END; float or integer mode |
| `Table` | Display | void | Unicode box borders (4 styles), striped rows, ANSI-safe column widths |
| `Alert` | Display | void | Bordered notification boxes — `success`, `error`, `warning`, `info`, `block` |
| `ProgressBar` | Display | void | Determinate (ETA + throughput) and indeterminate (bounce) modes |
| `SpinnerComponent` | Display | void | Non-blocking spinner; styles: `dots`, `line`, `bars`, `pulse`, `arc`, `bounce` |

`RadioGroup` and `SliderInput` are not documented in the module README but are fully implemented
and production-ready. Use `RadioGroup` for short mutually exclusive lists; use `SliderInput` for
continuous numeric ranges where keyboard precision matters more than typed input.

---

## MODULE CONTRACT — EVERY Provider.php IMPLEMENTS THIS

```php
interface ModuleContract
{
    // Single domain this module owns — must match module.json "solves"
    public function solves(): string;      // e.g. 'invoice.generation'

    // Contracts required from other modules — must match module.json "requires"
    public function requires(): array;     // e.g. [DatabasePort::class]

    // Contracts exposed to other modules — must match module.json "exposes"
    public function exposes(): array;      // e.g. [InvoiceServiceContract::class]

    // Register DI bindings into the module's scoped container
    public function register(ModuleContainer $container): void;

    // Register pipeline hooks and event subscriptions
    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void;
}
```

---

## module.json — SINGLE SOURCE OF TRUTH

```json
{
  "name":    "invoice",
  "version": "1.0.0",
  "solves":  "invoice.generation",
  "type":    "module",

  "requires": ["database.query"],
  "exposes":  ["InvoiceServiceContract"],

  "routes": [
    { "method": "GET",    "path": "/api/invoices",      "handler": "InvoiceController@index"   },
    { "method": "POST",   "path": "/api/invoices",      "handler": "InvoiceController@create"  },
    { "method": "GET",    "path": "/api/invoices/{id}", "handler": "InvoiceController@show"    },
    { "method": "PUT",    "path": "/api/invoices/{id}", "handler": "InvoiceController@update"  },
    { "method": "DELETE", "path": "/api/invoices/{id}", "handler": "InvoiceController@destroy" }
  ],

  "emits":   ["invoice.created", "invoice.paid"],
  "config": [
    "INVOICE_CURRENCY",
    { "key": "INVOICE_TAX_RATE", "type": "float", "required": false }
  ]
}
```

Rules:

- Plugin routes are declared HERE ONLY — never in PHP files (projects declare
  their own routes in `proj.json`; see "RESOURCE RESOLUTION")
- A plugin may declare `views` here (string/object/list) to register its view
  paths + namespace into the project-first cascade — see "RESOURCE RESOLUTION"
- Every env var the module reads MUST be in `config[]` or boot fails
- Cross-module access: inject published contract from `API/Contracts/` — never import internals

---

## PROVIDER.PHP — MODULE WIRING

```php
<?php
declare(strict_types=1);
namespace InvoiceModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use InvoiceModule\API\Contracts\InvoiceServiceContract;
use InvoiceModule\Application\Services\InvoiceService;
use InvoiceModule\Infrastructure\Persistence\InvoiceRepository;

class Provider implements ModuleContract
{
    public function solves(): string { return 'invoice.generation'; }

    public function requires(): array { return [DatabasePort::class]; }

    public function exposes(): array { return [InvoiceServiceContract::class]; }

    public function register(ModuleContainer $container): void
    {
        // Internal — throws ScopeViolationException if resolved from outside this module
        $container->bindInternal(InvoiceRepository::class, fn($c) =>
            new InvoiceRepository(
                $c->make(DatabasePort::class),
                $c->make(Identity::class),
            )
        );

        // Public — resolvable by modules that declare 'invoice.generation' in requires[]
        $container->bind(InvoiceServiceContract::class, fn($c) =>
            new InvoiceService(
                repository:  $c->make(InvoiceRepository::class),
                transaction: $c->make(TransactionManager::class),
                collector:   $c->make(DomainEventCollector::class),
                eventBus:    $c->make(EventBus::class),
                identity:    $c->make(Identity::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Register pipeline hooks
        // $http->hook('after.security', SomeStage::class, priority: 50);

        // Register event subscriptions
        // $events->subscribe('payment.succeeded', PaymentSucceededListener::class);
    }
}
```

---

## SECURITY GATEWAY — PRE-BOOTSTRAP

```
Request → FirewallLayer → RateLimiterLayer → CsrfTokenLayer → [AuthModule layer] → pipeline
               ↓                ↓                  ↓                  ↓
          deny(403)         deny(429)          deny(403)          deny(401)
          ZERO module cost at every denial
```

```php
interface SecurityLayerContract
{
    // NEVER throw — always return a verdict
    public function check(Request $request): SecurityVerdict;
}

SecurityVerdict::allow($request)          // proceed, Identity optionally attached
SecurityVerdict::deny(int $code, string $reason)  // stop, return HTTP error

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

    // Test helpers
    public static function asUser(string $userId, string $tenantId = 'tenant-1'): self { ... }
    public static function asAdmin(string $tenantId = 'tenant-1'): self                { ... }
    public static function guest(): self                                                { ... }
}
```

---

## HTTP PIPELINE — COMPLETE LIFECYCLE

```
1. CorrelationIdStage     generate/propagate X-Correlation-ID
2. SecurityStage          run SecurityGateway, attach Identity on clear
   ↳ after.security hooks module-registered stages here
3. ResolveStage           route-manifest.php lookup → service name (attaches route_entry)
4. LoadStage              dep graph calc → OnDemandLoader → ModuleContainer
   ↳ after.load hooks     module-registered stages here
   ↳ RouteFilterStage     runs the matched route's declared filters[] (auth, throttle, …)
5. ExecuteStage           service contract → DTO → controller → Response
   ↳ after.execute hooks  module-registered stages here
6. ErrorStage (wraps all) catches ALL Throwables → ErrorPipeline → HTTP response
```

Stage contract:
```php
interface HttpStageContract
{
    public function handle(Request $request, callable $next): Response;
}
```

Module registering a hook:
```php
public function boot(HttpPipeline $http, ...): void
{
    // Priority: 1-9 system, 10-19 security, 40-59 feature, 80-99 observability
    $http->hook('after.security', RateLimiterStage::class, priority: 10);
    $http->hook('after.load',     LocaleResolverStage::class, priority: 40);
    $http->hook('after.execute',  SecurityHeadersStage::class, priority: 90);
}
```

### TWO WAYS A STAGE RUNS — GLOBAL HOOK vs DECLARATIVE ROUTE FILTER

A stage (`HttpStageContract`) is wired through EXACTLY ONE mechanism — NEVER
both. Registering the same stage as a global hook AND a route filter makes it run
twice per request (e.g. a rate limiter would double-count).

| Aspect | Global hook | Declarative route filter |
|---|---|---|
| Register | `$http->hook(slot, Stage::class, priority)` | `$http->filter('alias', Stage::class)` |
| Runs on | EVERY request (stage self-gates internally) | ONLY routes that name the alias |
| Declared in | the registering plugin's `boot()` | the route's `filters[]` (module.json / proj.json) |
| Use for | always-on cross-cutting (CORS, SecureHeaders) | opt-in per route (auth, throttle, hmac, shield) |

A route opts into filters by name; they are compiled into the route manifest by
`CompileRouteManifestStage` and run by `RouteFilterStage` (at the after.load
position). `"alias:arg1,arg2"` passes args.

```jsonc
// module.json / proj.json route entry — string or list
{ "method": "POST", "path": "/api/tasks", "handler": "...@create",
  "filters": ["auth", "throttle:60,1"] }
```

ANY plugin publishes filter aliases from its `boot()` — the alias registry
(`FilterRegistry`) is shared, not owned by SecurityFilters:

```php
public function boot(HttpPipeline $http, ...): void
{
    $http->filter('json', RequireJsonStage::class);   // route opts in via "filters": ["json"]
}
```

`RouteFilterStage` reads the matched route's `filters[]` from the `route_entry`
attribute, resolves each alias to a stage instance (from `CoreContainer` when
bound, else `new`), and runs them as a NESTED onion around `$next` — so the usual
before/after semantics hold and filters run left-to-right in declaration order.
It exposes `active_filters` (alias list) + `filter_args` (parsed `:args`) as
request attributes so a stage can detect declarative invocation and read its
per-route config. A `RequireAuthStage` thus enforces when EITHER the path is in
`AUTH_PROTECTED_PATHS` (global) OR the route declared the `auth` filter.

```
✗ Registering the SAME stage as BOTH a global hook AND a route filter — runs twice
✗ Naming a filter alias in a route that no Provider::boot() registered — request-time throw
✗ Defining route filters anywhere but the route's filters[] in module.json / proj.json
✗ Two plugins binding the SAME alias to DIFFERENT stage classes — FilterRegistry throws
```

---

## CONTAINER ARCHITECTURE — bind-it ENGINE

Both `CoreContainer` and `ModuleContainer` extend `PHPShots\Common\Container` from the **bind-it**
package. This gives reflection-based autowiring, contextual bindings, extenders, and PSR-11
compliance as a foundation. GDA scope rules are layered on top.

**CoreContainer** — app-lifetime (one per worker process):
```php
$core->instance(DatabasePort::class, $mySQLAdapter);   // store port implementation
$core->singleton(TransactionManager::class, fn($c) => new TransactionManager(...));
$core->freeze();        // called during materialize() (first entry-point call) after all
                        //   modules boot — writes forbidden thereafter
$core->isFrozen(): bool // check if locked (false after build(), true after materialize())
// getInstance() and setInstance() are DISABLED — throw LogicException (no global singleton)
```

**ModuleContainer** — request-scoped (new instance per request OR per job, discarded after):
```php
// Internal binding — throws ScopeViolationException if resolved from outside this module
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

**OnDemandLoader** provides two methods:
```php
// For HTTP: extracts Identity from the Request
$loader->load(DependencyGraph $graph, Request $request): ModuleContainer;

// For Worker jobs (no HTTP Request available): pass ?Identity (null → guest)
$loader->loadWithIdentity(DependencyGraph $graph, ?Identity $identity): ModuleContainer;
```

---

## MODULE DIRECTORY STRUCTURE

```
modules/{name}/
├── module.json                              ← single source of truth
├── API/
│   ├── Contracts/{Name}ServiceContract.php ← published interface
│   └── IntegrationEvents/{Name}CreatedIntegrationEvent.php
├── Domain/
│   ├── Entities/{Name}.php                 ← final class, private constructor
│   ├── ValueObjects/{Field}.php            ← final readonly class
│   ├── Rules/{Rule}.php                    ← static check() methods
│   └── Events/{Name}CreatedDomainEvent.php ← final readonly class
├── Application/
│   └── Services/{Name}Service.php          ← transaction + event pattern
├── Infrastructure/
│   ├── Persistence/{Name}Repository.php    ← DatabasePort only
│   ├── Gateways/{Vendor}{Name}Gateway.php  ← vendor SDK only
│   └── Http/Controllers/{Name}Controller.php ← 3-line controllers
└── Provider.php                            ← implements ModuleContract
```

---

## DOMAIN LAYER RULES

Zero external imports. Every file in `Domain/` has only:
```php
<?php declare(strict_types=1);
namespace {Module}Module\Domain\{Entities|ValueObjects|Events|Rules};
// ONLY imports from within Domain/ are permitted here
```

**Entity pattern:**
```php
final class Invoice
{
    private array $domainEvents = [];

    private function __construct(             // ALWAYS private constructor
        private readonly InvoiceId $id,
        private InvoiceStatus $status,
    ) {}

    public static function create(...): self  // named constructor — records events
    {
        $inv = new self(id: InvoiceId::generate(), status: InvoiceStatus::DRAFT);
        $inv->domainEvents[] = new InvoiceCreatedDomainEvent($inv);
        return $inv;
    }

    public static function reconstitute(...): self  // for DB hydration — NO events
    {
        $inv = new self(...);
        return $inv;
    }

    public function issue(): void             // state transition — checks precondition
    {
        if ($this->status !== InvoiceStatus::DRAFT) {
            throw new \DomainException('Can only issue a draft invoice');
        }
        $this->status = InvoiceStatus::ISSUED;
        $this->domainEvents[] = new InvoiceIssuedDomainEvent($this);
    }

    public function releaseEvents(): array    // returns AND clears buffer
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }
}
```

**Value Object pattern:**
```php
final readonly class Money
{
    private function __construct(
        private int    $amount,    // ALWAYS integer cents — NEVER float
        private string $currency,  // ISO 4217
    ) {
        if ($this->amount < 0) throw new \DomainException('Money cannot be negative');
    }

    public static function of(int|float $amount, string $currency): self
    {
        return new self((int) round($amount * 100), strtoupper($currency));
    }

    public function add(self $other): self     // operations return NEW instances
    {
        if ($this->currency !== $other->currency) throw new \DomainException('Currency mismatch');
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function value(): float    { return $this->amount / 100; }
    public function amount(): int     { return $this->amount; }
    public function currency(): string { return $this->currency; }
}
```

---

## APPLICATION SERVICE LAYER — MANDATORY PATTERN

```php
final class InvoiceService implements InvoiceServiceContract
{
    public function __construct(
        private readonly InvoiceRepository    $repository,
        private readonly TransactionManager   $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus             $eventBus,
        private readonly Identity             $identity,   // from SecurityGateway
    ) {}

    public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
    {
        // 1. Authorization check — always first in mutating methods
        if ($dto->clientId !== $this->identity->userId
            && !$this->identity->hasPermission('invoice:create-for-others')) {
            throw new ServiceException(
                'invoice.creation.unauthorized',
                layer:   'service.invoice',
                context: ['clientId' => $dto->clientId],
            );
        }

        // 2. Transaction + domain event collection — MANDATORY SHAPE
        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $invoice = Invoice::create(
                InvoiceNumber::generate(),
                ClientId::from($dto->clientId),
                new \DateTimeImmutable($dto->dueDate),
            );

            foreach ($invoice->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->save($invoice);
            $this->transaction->commit();

        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();  // ALWAYS — no phantom events on rollback
            throw new ServiceException('invoice.create.failed', layer: 'service.invoice', previous: $e);
        }

        // 3. Integration event dispatch — ONLY after successful commit — NEVER inside try
        $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(
            invoiceId:  $invoice->id()->value(),
            clientId:   $dto->clientId,
            amount:     $invoice->total()->value(),
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            version:    '1.0',
        ));

        return InvoiceResponseDTO::from($invoice);
    }
}
```

---

## REPOSITORY LAYER RULES

```php
final class InvoiceRepository
{
    public function __construct(
        private readonly DatabasePort $db,       // ONLY external dependency
        private readonly Identity     $identity, // for tenant scoping
    ) {}

    public function find(string $id): Invoice
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM invoices WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                ['id' => $id, 'tenant' => $this->identity->tenantId]  // ALWAYS tenant scope
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(  // ALWAYS translate \PDOException
                "Failed to find invoice [{$id}]",
                layer: 'repository.invoice', context: ['id' => $id], previous: $e
            );
        }

        if ($row === null) throw new RepositoryException("Invoice [{$id}] not found", layer: 'repository.invoice');

        return InvoiceHydrator::hydrate($row);
    }
}
```

---

## GATEWAY LAYER RULES

```php
final class StripePaymentGateway implements PaymentGatewayContract
{
    public function __construct(
        private readonly \Stripe\StripeClient $stripe,  // ONLY vendor SDK
    ) {}

    public function charge(ChargeDTO $dto): ChargeResult
    {
        try {
            $intent = $this->stripe->paymentIntents->create([...]);
            return match($intent->status) {
                'succeeded' => ChargeResult::success($intent->id),
                default     => ChargeResult::failed($intent->status),
            };
        } catch (\Stripe\Exception\CardException $e) {
            throw new GatewayException(  // ALWAYS translate — no vendor exceptions escape
                'Card declined: ' . $e->getError()->message,
                layer: 'gateway.stripe.charge',
                context: ['decline_code' => $e->getError()->decline_code],
                previous: $e,
            );
        }
        // Catch EVERY possible vendor exception explicitly
    }
}
```

---

## CONTROLLER LAYER RULES

```php
final class InvoiceController
{
    public function __construct(
        private readonly InvoiceServiceContract $service,  // contract only — never repository
    ) {}

    // 3 lines maximum per method
    public function create(Request $request): Response
    {
        $dto    = CreateInvoiceDTO::fromRequest($request);  // validation here
        $result = $this->service->create($dto);             // business logic in service
        return Response::json($result->toArray(), 201);     // just translation
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json($this->service->find($id)->toArray());
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->service->delete($id);
        return Response::empty(204);
    }
}
```

### Base controllers (project layer — `Project\Http\Controllers\`)

Optional base classes in `projects/Http/Controllers/` (namespace `Project\`).
Project layer, NOT kernel — view rendering + cookies are plugin concerns.

- `ApiController` — JSON helpers (`ok`, `created`, `noContent`, `paginated`,
  `okOrNotFound`, `notFound`, `forbidden`, `unprocessable`, `identity`). Pure
  kernel types, zero plugin coupling.
- `ViewController` — HTML helpers (`view`, `viewNotFound`, `redirect`, `back`);
  injects the View plugin's `ViewRendererContract`.
- Both `use InteractsWithCookies` — wraps every public `CookieJar` method:
  `cookie`, `queueCookie`, `rememberCookie`, `forgetCookie`, `hasQueuedCookie`,
  `decryptCookie`, `cookieJar`. Defaults come from `config/cookie.php` + `.env`.

### RequestAware — kernel contract, actions take route params ONLY

Both bases implement `Kernel\Http\Contracts\RequestAware`
(`setRequest(Request): static`). `ExecuteStage` checks `instanceof RequestAware`
and, when true, calls `setRequest($request)` (the container-bearing request) and
invokes the action as `$method(...$routeParams)` — **without `$request`**. Plain
controllers keep `$method($request, ...$params)` — fully backward compatible.

```php
final class CartController extends ApiController        // RequestAware
{
    public function show(string $id): Response          // route param only — no $request
    {
        $this->queueCookie('last_viewed', $id);         // request injected by the kernel
        return $this->okOrNotFound($this->cart->find($id)?->toArray());
    }
}
```

Raw request inside the action: `$this->request`. Cookie helpers also accept an
explicit `?Request` override.

```
✗ Coupling the kernel to the View plugin or a controller base — RequestAware is the only kernel↔controller seam
✗ Adding $request to a RequestAware controller action — it receives route params only
✗ Calling CookieJar::applyTo() from a controller — QueuedCookiesStage flushes the jar automatically (double-apply otherwise)
```

---

## EVENT SYSTEM — TWO TYPES, TWO RULES

| Aspect | Domain Event | Integration Event |
|---|---|---|
| Location | `Domain/Events/` | `API/IntegrationEvents/` |
| Scope | Internal to module | Cross-module |
| Dispatch | `collector->collect()` DURING tx | `eventBus->dispatch()` AFTER commit |
| On rollback | `collector->discard()` | Never dispatched |
| Constructor params | Domain value objects | Primitives only (string/int/float) |

```php
// Domain event — internal, past tense, no external deps
final readonly class InvoiceCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public readonly InvoiceId         $invoiceId,   // domain types OK
        public readonly Money             $total,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}

// Integration event — public, versioned, primitives only
final readonly class InvoiceCreatedIntegrationEvent implements IntegrationEventContract
{
    public string $version = '1.0';
    public function __construct(
        public readonly string $invoiceId,   // primitives only — other modules may not have your VOs
        public readonly float  $amount,
        public readonly string $occurredAt,  // RFC3339 string
    ) {}
    public function name(): string    { return 'invoice.created'; }
    public function version(): string { return $this->version; }
    public function payload(): array  { return get_object_vars($this); }
}

// Subscribing in Provider::boot()
$events->subscribe('payment.succeeded', PaymentSucceededListener::class);
```

---

## EXCEPTION HIERARCHY

```php
abstract class FrameworkException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $layer   = '',  // 'service.invoice', 'gateway.stripe.charge'
        public readonly array  $context = [],  // typed key→value for error log
        int $code = 0, ?\Throwable $previous = null
    ) { parent::__construct($message, $code, $previous); }
}

// Throw rules:
// Domain layer     → throw new \DomainException(...)   (PHP built-in)
// Service layer    → throw new ServiceException('module.action.verb', layer: '...', previous: $e)
// Repository layer → throw new RepositoryException(...) translated from \PDOException
// Gateway layer    → throw new GatewayException(...) translated from ALL vendor exceptions
// Security layer   → return SecurityVerdict::deny() — NEVER throw

class SecurityException   extends FrameworkException {}  // → HTTP 401/403   severity: warning
class DomainException     extends FrameworkException {}  // → HTTP 422       severity: info
class ServiceException    extends FrameworkException {}  // → HTTP 422/500   severity: warning
class RepositoryException extends FrameworkException {}  // → HTTP 500       severity: critical
class GatewayException    extends FrameworkException {}  // → HTTP 502       severity: critical
class KernelException     extends FrameworkException {}  // → HTTP 500       severity: critical

class ValidationException extends FrameworkException
{
    public function __construct(
        public readonly array $errors,         // field → message(s)
        string $message = 'Validation failed.',
    ) { parent::__construct($message, layer: 'validation'); }
}
```

---

## PORT INTERFACES

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

interface CachePort {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function has(string $key): bool;
    public function remember(string $key, int $ttl, callable $callback): mixed;
    public function increment(string $key, int $by = 1): int;
    public function deletePattern(string $pattern): int;
    public function flush(): bool;
}

interface QueuePort {
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string;
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string;
    public function size(string $queue = 'default'): int;
}

interface MailPort {
    public function send(string|array $to, string $subject, string $view, array $data = []): void;
    public function queue(string|array $to, string $subject, string $view, array $data = []): string;
}

interface StoragePort {
    public function store(string $contents, string $filename, string $path = '', string $visibility = 'private'): string;
    public function get(string $path): string;
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
}

interface HttpClientPort {   // outbound HTTP for Gateways — adapter: plugins/HttpClient (cURL)
    public function request(string $method, string $url, array $options = []): HttpClientResponse;
    public function get(string $url, array $query = []): HttpClientResponse;
    public function post(string $url, array $data = []): HttpClientResponse;   // + put/patch/delete
}

interface SessionPort {      // request-scoped — adapter: plugins/Session (essential module)
    public function start(?string $id = null): void;
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value): void;
    public function pull(string $key, mixed $default = null): mixed;  // + has/push/increment/forget/flush/all
    public function flash(string $key, mixed $value): void;           // single-request data
    public function token(): string;                                  // CSRF
    public function regenerate(): void; public function invalidate(): void; public function save(): void;
}
```

---

## JOB CONTRACT

```php
interface JobContract
{
    public function handle(JobPayload $payload): JobResult;  // throw to retry
    public function failed(JobPayload $payload, \Throwable $e): void;  // after max retries
}

JobResult::success(['processed' => 142])          // completed
JobResult::skipped('Invoice already paid')        // done, no retry triggered

// module.json for a job:
{
  "type": "job",
  "queue": "emails",
  "retry": { "max": 3, "strategy": "exponential", "jitter": true },
  "timeout": 30,
  "requires": ["mail.port", "invoice.generation"]
}
```

---

## RESPONSE FACTORY

```php
Response::json($data, 200)          // OK
Response::json($data, 201)          // Created
Response::empty(204)                // No Content
Response::notFound()                // 404
Response::unauthorized()            // 401
Response::forbidden()               // 403
Response::unprocessable($errors)    // 422 with field errors
Response::serverError()             // 500
Response::redirect($url, 302)       // Redirect

// Error response body shape (always):
{
  "error": {
    "code":      "invoice.not_found",
    "message":   "Invoice [id] was not found.",
    "requestId": "20250615-a3f8b2c1",
    "fields":    { ... }              // present only on 422
  }
}
```

---

## TESTING PATTERNS

```php
// Service test — always use fakes, NEVER real infrastructure
$repo      = new InMemoryInvoiceRepository();
$txn       = new FakeTransactionManager();
$bus       = new FakeIntegrationEventBus();
$collector = new DomainEventCollector();
$identity  = Identity::asUser('user-1', 'tenant-abc');

$sut = new InvoiceService($repo, $txn, $collector, $bus, $identity);
$result = $sut->create($validDto);

// Assert outcomes:
$this->assertTrue($txn->wasCommitted());
$bus->assertDispatched(InvoiceCreatedIntegrationEvent::class, times: 1);
$this->assertNotNull($repo->find($result->invoiceId));

// Test rollback path:
$repo->failOnNextSave();
try { $sut->create($validDto); } catch (ServiceException) {}
$this->assertTrue($txn->wasRolledBack());
$bus->assertNotDispatched(InvoiceCreatedIntegrationEvent::class);
```

---

## WHAT CLAUDE MUST NEVER GENERATE FOR THIS PROJECT

```
✗ Laravel/Symfony/Slim patterns, classes, or conventions
✗ Eloquent, Active Record, or any ORM in the Domain layer
✗ Routes defined in PHP — only in module.json
✗ Env vars used in a module but not declared in module.json config[]
✗ getenv() for a .env value in first-party code — use the env() helper ($_ENV is the source of truth, putenv() is not called)
✗ Business logic in the Kernel — kernel knows nothing about any domain
✗ Integration events dispatched inside a try{} block — ONLY after commit
✗ Missing collector->discard() in a catch block — phantom events
✗ Vendor exceptions (\PDOException, Stripe, Guzzle) escaping their layer
✗ Another module's internal class imported — use published contract
✗ Security authorization in SecurityGateway layers — belongs in Service
✗ float for money — use Money value object with integer cents
✗ throw inside catch without rollback first
✗ hash comparison with === for tokens — always hash_equals()
✗ Static properties in request-scoped classes — they leak between requests in Swoole
✗ Direct instantiation of port implementations — always inject via DI
✗ Injecting Request or Response objects into a Service or Repository
✗ Business logic in a Controller — max 3 lines: DTO → service → Response
✗ CoreContainer::getInstance() or ::setInstance() — both throw LogicException (disabled)
✗ ModuleContainer::getInstance() or ::setInstance() — both throw LogicException (disabled)
✗ Calling CoreContainer::bind/singleton/extend after the kernel materializes (first entry-point call) — container is frozen
✗ Resolving an internal binding from outside its owning module — use makeInScope() or published contract
✗ Mutating ModuleContainer without calling reset() at end-of-request in Swoole workers
✗ CommandContract, Arguments, or Output from Cli/ — they are @deprecated; extend AbstractCommand
✗ Route handler string without @ separator — must be 'Controller@method' format
✗ New local modules placed under projects/ — use plugins/ with Plugins\ namespace instead
✗ Plugin handler strings without the full Plugins\{Name}\... path in module.json
✗ Relying on plugin load order for route/view resolution — it is deterministic (project-over-plugin)
✗ Letting a plugin override a project route/view implicitly — only via an explicit lower view `priority`
✗ Two plugins claiming the same route, or the same unnamespaced view name — boot fails / use namespace::view
✗ Defining project routes in PHP — declare them in proj.json routes[] or Kernel::withRoutes()
✗ Laravel/Doctrine/Symfony migrations in this project — ONLY use LetMigrate
✗ Eloquent models, Doctrine entities, or ORM migrations — LetMigrate is standalone
✗ Routes in migrations or business logic — migrations ONLY define schema
✗ float for money in migrations — use decimal(precision, scale) with integer storage
✗ Writing migrations without a matching down() rollback
✗ Hardcoding database table names — use string literals, never interpolation
✗ ON UPDATE CURRENT_TIMESTAMP on PostgreSQL — LetMigrate auto-creates BEFORE UPDATE triggers
✗ onUpdateCurrentTimestamp() on non-timestamp columns — only for DATE/DATETIME/TIMESTAMP
✗ --seed in refresh without wiring SeederRunner to MigrateRefreshCommand
✗ Mutating migration files after they've been applied — create a NEW migration instead
✗ Migrations that don't declare required env vars in LetMigrate config
✗ Pretend mode in production — only for CI previews (testing compiled SQL)
```

---

## RESOURCE RESOLUTION — DETERMINISTIC PROJECT-OVER-PLUGIN PRIORITY

Routes and views resolve through a single, predictable priority model:
**project resources always win over plugin resources by default.** Order is
never implicit or load-order dependent — it is fixed at boot and compiled into
manifests. A plugin may only outrank the project by EXPLICITLY opting in (a
lower numeric `priority`); the platform never lets a plugin override the project
silently.

### Routes — project overrides plugin

| Source | Declared in | Scope (`solves`) | Compiled |
|---|---|---|---|
| Plugin route | plugin `module.json` `routes[]` | the plugin's domain | first |
| Project route | `proj.json` `routes[]` **or** `Kernel::withRoutes([...])` | synthetic `__project__` | LAST |

`CompileRouteManifestStage` compiles plugin routes first, then project routes.
A project route declaring the same `METHOD path` as a plugin route **overrides**
it (the manifest records `overrides`). Two plugins claiming the same route still
hard-fail at boot. Project routes carry no module dependency graph: they resolve
under the synthetic `__project__` scope (a no-module service entry with empty
`requires`), and the controller — referenced by its FULL class path — autowires
from the request container with full port access but runs no module `register()`.

```jsonc
// projects/<name>/proj.json (or the flat project root proj.json)
{
  "name": "shop",
  "routes": [
    { "method": "GET", "path": "/",     "handler": "Shop\\Http\\HomeController@index" },
    { "method": "GET", "path": "/ping", "handler": "Shop\\Http\\HomeController@ping"  }
  ]
}
```

`EntryHelpers::projectRoutes($projectPath)` reads `proj.json` `routes[]`; the
project bootstrap passes them to `->withRoutes(...)`. Keep project controllers
THIN (orchestrate published plugin contracts) — real domain logic stays in
plugins.

### Views — project-first cascade + namespacing

`CompileViewManifestStage` compiles `view-manifest.php` from every `views`
declaration (project `proj.json` + each plugin `module.json`):

```php
[ 'global'     => [ '/abs/project/views', '/abs/plugin/views', ... ],  // priority-ordered
  'namespaces' => [ 'task' => [ '/abs/plugin/task/views' ] ] ]
```

Priority model — **LOWER wins (searched first):**
- PROJECT view paths default to **priority 0** → always highest precedence.
- PLUGIN view paths default to **priority 100** → fallbacks below the project.

Resolution in `PhpViewRenderer`:
- `render('welcome')` — plain name → walks the global cascade (project first,
  plugin fallbacks). Project overrides a plugin view of the same name.
- `render('task::welcome')` — namespaced → checks the PROJECT's override first
  (`{global-path}/task/welcome.php`), THEN the `task` namespace's own dir. Lets a
  project override one targeted plugin view while the plugin stays canonical, and
  prevents cross-plugin name collisions.

Declaration shapes (`module.json` "views" / `proj.json` "views"):

```jsonc
"views": "resources/views"                                   // shorthand
"views": { "path": "resources/views",
           "namespace": "task", "priority": 100, "global": true }  // full
"views": [ { ... }, { ... } ]                                // several sources
```

- `namespace` defaults to the module `name` (project sources have no default
  namespace — they feed the global cascade only).
- `priority` is the configurable knob. A plugin setting `"priority": -1`
  EXPLICITLY preempts the project — the only sanctioned way a plugin wins.
- `global: false` exposes a source ONLY under its namespace (not in the plain
  cascade) — maximum collision safety.

`VIEW_PATHS` env still works: it is **prepended** to the compiled cascade, so an
operator can raise a project path's priority at runtime but can NEVER let a
plugin implicitly outrank the project. `VIEW_EXTENSIONS` / `VIEW_SAVE_DATA`
behave as before.

### Rules

```
✓ Project resources (routes, views) override plugin resources BY DEFAULT.
✓ Project routes compile last; project view paths sort to priority 0.
✓ Use `namespace::view` to target a plugin view and to avoid name collisions.
✓ A plugin overrides the project ONLY via an explicit lower `priority`.
✗ Relying on plugin load order for resolution — it is fully deterministic.
✗ Defining project routes in PHP — declare them in proj.json / withRoutes().
✗ Letting two plugins claim the same route or unnamespaced view name.
```

---

## PLUGINS FOLDER — LOCAL BUSINESS MODULES

Local application-specific modules live in `plugins/`, NOT `projects/`.

| Folder      | Role                                                                   |
|-------------|------------------------------------------------------------------------|
| `modules/`  | First-party framework packages (git submodules, may be published)      |
| `projects/` | Per-project wiring + optional project-only `src/` (`Projects\<Name>\`) and project-local `app/` entries |
| `plugins/`  | Local business modules, full GDA structure, `Plugins\` namespace       |

**Autoload:** `"Plugins\\": "plugins/"` in `composer.json` `autoload.psr-4`.

**Namespace:** `Plugins\{ModuleName}\` — PascalCase folder matches PascalCase namespace root.

**module.json handlers** must use the full class path:

```json
{ "handler": "Plugins\\Task\\Infrastructure\\Http\\TaskController@index" }
```

**Registered plugins:**

| Plugin | Namespace        | Solves            |
|--------|------------------|-------------------|
| Task   | `Plugins\Task\`  | `task.management` |

**Infrastructure plugins (port adapters / pipeline stages):**

| Plugin        | Solves                  | Provides (port / stage)                                  | Activation |
|---------------|-------------------------|----------------------------------------------------------|------------|
| Storage       | `storage.local`         | `StoragePort` (local disk, signed temp URLs)             | on-demand  |
| View          | `view.rendering`        | `ViewRendererContract` (PHP templates: layouts, sections, decorators; project-first cascade + `ns::view`) | on-demand  |
| HttpClient    | `http.client`           | `HttpClientPort` (cURL, fluent builder, multipart)       | on-demand  |
| Session       | `session.management`    | `SessionPort` (file/array handlers, flash, CSRF token)   | essential  |
| Cookie        | `http.cookies`          | `CookieJar` + queued-cookie flush stage (encrypts via `EncryptionPort`) | essential |
| RedisCache    | `cache.redis`           | `CachePort` + `QueuePort` (ext-redis, in-memory fallback)| essential  |
| SecurityFilters | `http.security_filters` | global hooks: CORS, SecureHeaders. Route-filter aliases: `auth`, `throttle`, `hmac`, `shield` | hooked + filters |

**Adding a new plugin:** create `plugins/{Name}/`, implement all GDA layers under
`Plugins\{Name}\`, then add `Plugins\{Name}\Provider::class` to the relevant
`projects/{project}/bootstrap/app.php`.

---

## MODULE ACTIVATION — ON-DEMAND vs ESSENTIAL vs PORT

A module's `register()` (its DI bindings) runs per request ONLY when the module
is in that request's dependency graph. The graph is built from the resolved
route service's transitive `requires[]` (see `DependencyGraphCalculator`). There
are three ways a capability becomes available to a request:

1. **App-lifetime port** — bound in `bootstrap/base.php` `withPorts([...])` into
   the `CoreContainer`. Always resolvable in any request via the ModuleContainer
   core fallback. Correct for STATELESS infrastructure (DB, hashing, encryption,
   in-memory cache). NEVER use for request-scoped state — it would leak across
   requests under OpenSwoole.

2. **On-demand module** — listed in `withModules([...])`. Its `boot()` hooks are
   registered at build, but its `register()` bindings only run when a route
   pulls it into the graph. A consuming module opts in via its `module.json`:

   ```json
   { "requires": ["http.client", "storage.local"] }
   ```

   Use for capabilities only SOME routes need (outbound HTTP, file storage).

3. **Essential module** — listed in `withEssentialModules([...])`. Registered
   into EVERY request container regardless of the route graph, so its
   request-scoped services are available app-wide. Use sparingly for
   cross-cutting REQUEST-SCOPED infrastructure that cannot be an app-lifetime
   port (sessions, cookies, the Redis cache override). Each essential pays a
   per-request `register()` cost, so keep adapters self-guarding (no work until
   first use).

Rule of thumb: stateless + always → port; stateful + always → essential;
needed by some routes → on-demand with `requires[]`.

---

## LAYER-SPECIFIC CONTEXT FILES

For deeper context on any layer, reference:

- `@docs/ai-context/00_SENTINEL_OVERVIEW.md`  — full architecture + request lifecycle
- `@docs/ai-context/03_DOMAIN.md`             — entity, value object, domain event patterns
- `@docs/ai-context/04_SERVICE.md`            — service layer, transaction + event pattern
- `@docs/ai-context/05_REPOSITORY.md`         — repository layer rules in detail
- `@docs/ai-context/06_GATEWAY.md`            — gateway layer, vendor exception translation
- `@docs/ai-context/07_CONTROLLER.md`         — HTTP controllers, DTO validation pattern
- `@docs/ai-context/08_EVENTS.md`             — domain vs integration events, EventBus
- `@docs/ai-context/09_SECURITY.md`           — SecurityGateway, Identity, JWT internals
- `@docs/ai-context/10_TESTING.md`            — test patterns, port fakes, service tests
- `@docs/ai-context/13_ANTIPATTERNS.md`       — 12 wrong/correct code pairs
- `@docs/ai-context/16_PLUGINS.md`            — plugins folder convention, local module checklist
- `@docs/ai-context/18_MIGRATIONS.md`         — LetMigrate engine, migrations, seeders
- `@docs/ai-context/19_DATABASE.md`           — multi-driver Database module, DatabasePort adapter, connections
