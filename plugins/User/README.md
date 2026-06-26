# User Plugin

> Solves: **`user.management`** · Namespace: **`Plugins\User\`** · Type: on-demand GDA module

The User plugin owns the **user.management** business domain on the AlfacodeTeam
PhpServicePlatform (GDA) framework. It provides enterprise-grade user
registration, lookup, partial update, email verification, soft-deletion, and
**timing-safe, rate-limited credential verification** — all over the GLOBAL
central `users` identity table.

It is the canonical reference for how a first-party plugin is structured: pure
Domain, an Application service that owns the transaction + events, Infrastructure
adapters behind ports, and a published API contract that other modules consume.

---

## Table of contents

1. [What it does](#what-it-does)
2. [Architecture at a glance](#architecture-at-a-glance)
3. [Directory layout](#directory-layout)
4. [Data model](#data-model)
5. [HTTP API](#http-api)
6. [Web UI (AJAX + CSRF)](#web-ui-ajax--csrf)
7. [Security model](#security-model)
8. [Reliability: the transactional outbox](#reliability-the-transactional-outbox)
9. [Installation & wiring](#installation--wiring)
10. [Configuration](#configuration)
11. [Using it from another plugin](#using-it-from-another-plugin)
12. [Using it from a project](#using-it-from-a-project)
13. [CLI](#cli)
14. [Testing](#testing)
15. [Extending the pattern](#extending-the-pattern)

---

## What it does

| Capability | Entry point | Notes |
|---|---|---|
| Register a user | `POST /api/users` | Public, rate-limited; emits `user.registered` |
| List users | `GET /api/users` | Admin-only; keyset paginated |
| Show a user | `GET /api/users/{id}` | Self or `user:read-any` |
| Update (partial) | `PUT/PATCH /api/users/{id}` | Self or `user:update-any`; optimistic-locked; emits `user.updated` |
| Verify email | `POST /api/users/{id}/verify-email` | Pending → Active; emits `user.updated` |
| Soft-delete | `DELETE /api/users/{id}` | Self or `user:delete-any`; emits `user.deleted` |
| Verify credentials | `UserServiceContract::verifyCredentials()` | Timing-safe, lockout, rehash-on-login (used by an Auth module) |
| HTML UI | `GET /users`, `/users/create`, `/users/{id}`, `/users/{id}/edit` | AJAX-driven, cookie auth, CSRF on every form |

---

## Architecture at a glance

```
HTTP ─▶ UserController (thin)              CLI ─▶ user:outbox:relay
          │  DTO → service → Response               │
          ▼                                         ▼
   UserServiceContract  ◀── published to other modules
          │
   UserService (Application)
   ├─ authorization (Identity)            ── self-or-permission
   ├─ TransactionManager  begin/commit/rollback
   ├─ DomainEventCollector (in-tx buffer)
   ├─ OutboxPort  ─▶ user_outbox (same tx)  ── at-least-once events
   ├─ HashingPort (crypto.services)         ── bcrypt, rehash-on-login
   ├─ CachePort  ─▶ login lockout
   └─ AuditLogger
          │
   UserStore (port)  ◀── UserRepository (central DatabasePort, global, version-locked)
          │
   User aggregate (Domain — zero external imports)
   ├─ UserId (monotonic ULID), Username, Email, UserStatus, PasswordPolicy
   └─ records UserRegistered/Updated/Deleted domain events
```

**The five GDA access rules hold:** Controller → Service (contract only),
Service → Repository + Gateway, Repository → DatabasePort only, Domain imports
nothing external.

---

## Directory layout

```
plugins/User/
├── module.json                       single source of truth (routes, requires, config)
├── Provider.php                      DI wiring + CLI registration
├── API/
│   ├── Contracts/UserServiceContract.php       published interface
│   ├── DTOs/                                    Register/Update/VerifyEmail/User/ListUsersQuery/UserPage
│   └── IntegrationEvents/                       UserRegistered/Updated/Deleted + GenericIntegrationEvent
├── Application/
│   ├── Contracts? (none)
│   ├── Ports/ UserStore.php, OutboxPort.php     internal DIP seams (testability)
│   └── Services/UserService.php                 transaction + events + authz + lockout
├── Domain/
│   ├── Entities/User.php                        aggregate, private ctor, named constructors
│   ├── Events/                                   UserRegistered/Updated/Deleted domain events
│   ├── Exceptions/DuplicateUserException.php
│   └── ValueObjects/  UserId, Ulid, Username, Email, UserStatus, PasswordPolicy
├── Infrastructure/
│   ├── Audit/AuditLogger.php
│   ├── Cli/RelayUserOutboxCommand.php           user:outbox:relay
│   ├── Http/Controllers/  UserController, UserPageController
│   ├── Outbox/  OutboxWriter, OutboxRelay
│   └── Persistence/UserRepository.php           DatabasePort only
├── config/user.php
├── database/
│   ├── migrations/  create_user_table, create_user_outbox_table
│   ├── seeders/UserSeeder.php
│   └── factories/UserFactory.php
└── resources/views/  layouts/app.php, users/{index,create,edit,show}.php
```

---

## Data model

`users` is the **GLOBAL central identity table** (authentication is centralized,
username/email globally unique). Owned by the migration; the repository never
alters schema.

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | internal surrogate (never leaves persistence) |
| `user_id` | char(31) | **public** ULID identifier |
| `username` | varchar(50) | **globally** unique |
| `email` | varchar(150) | **globally** unique, lowercased |
| `password_hash` | char(60) null | bcrypt (exactly 60 chars); null when only an external IdP is used |
| `auth_provider` | varchar(30) | `local`\|`google`\|`github`\|`saml` |
| `provider_subject` | varchar(191) null | `sub`/`oid` from the external IdP |
| `is_platform_admin` | bool | global super-admin |
| `remember_token` | char(64) null | SHA-256 of the remember-me token |
| `status` | tinyint | 1=active, 2=inactive, 3=pending |
| `version` | int unsigned | optimistic-lock version |
| `email_verified_at` | timestamp null | set on confirmation |
| `last_login_at` | timestamp null | updated on successful login |
| `created_at`/`updated_at`/`deleted_at` | timestamps | soft-delete aware |

Uniqueness is **global** (`uniq_username`, `uniq_email`, `uniq_provider_subject`).

The repository and the `user_outbox` writer are **pinned to the central
connection** (the `ConnectionManager` default) so identity I/O always targets the
central database regardless of any per-request `DatabasePort` rebinding. A second
table, `user_outbox`, stores integration events for reliable delivery.

---

## HTTP API

All API responses use the framework envelope:

```jsonc
// success
{ "data": { "id": "01J…", "username": "jane", "email": "jane@example.com",
            "status": "active", "emailVerified": true, "createdAt": "2026-…" } }

// list (keyset paginated)
{ "data": [ … ], "meta": { "count": 25, "limit": 25, "has_more": true,
                           "next_cursor": "01J…" } }

// error
{ "error": { "code": "…", "message": "…", "fields": { "email": "…" } } }
```

### Register

```bash
curl -X POST https://app.example.com/api/users \
  -H 'Content-Type: application/json' \
  -d '{"username":"jane","email":"jane@example.com","password":"C0rrectHorse!"}'
# 201 → { "data": { … } }   emits user.registered
```

### List (paginate)

```bash
curl 'https://app.example.com/api/users?limit=50&after=01J…' \
  -H 'Authorization: Bearer <token>'    # or same-site session cookie
```

### Update (partial / PATCH semantics)

```bash
curl -X PUT https://app.example.com/api/users/01J… \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: …' \
  -d '{"email":"new@example.com"}'      # only changed fields; bumps version
```

A concurrent edit that loses the version race → **HTTP 409** (OptimisticLock).
A duplicate username/email → **HTTP 409/422** (DuplicateUserException).

---

## Web UI (AJAX + CSRF)

`UserPageController` renders four pages (`/users`, `/users/create`,
`/users/{id}`, `/users/{id}/edit`). Each is a thin HTML shell that hydrates over
AJAX against `/api/users`. Authentication is **same-site cookie** (no bearer
token in the browser).

**CSRF on every form:** the page controller (via `ViewController` →
`InteractsWithCsrf`) mints an HMAC token bound to a dedicated `csrf_bind`
cookie. Each page exposes it as `<meta name="csrf-token">` and a hidden
`_csrf_token` field; the shared `window.UserApp` client sends it as the
`X-CSRF-Token` header on every unsafe (POST/PUT/PATCH/DELETE) request.

> **Project requirement:** wire a `CsrfTokenLayer` in `withSecurity()` with
> `bindCookie: 'csrf_bind'`, the same `lifetime` as `CSRF_LIFETIME`, and **do not
> exempt `/api`** (the UI authenticates by cookie, so the write endpoints must be
> CSRF-checked). Otherwise tokens are sent but never validated.

---

## Security model

| Concern | Mechanism |
|---|---|
| Authorization | In the **service**: admins act on anyone (`user:list`, `user:*-any`); a non-admin only on their own record (`hash_equals` self-check) |
| Password storage | `HashingPort` (bcrypt); never `password_hash()` directly; plaintext never persisted/logged/returned |
| Password strength | `PasswordPolicy` VO — length 12–72, ≥3 char classes, deny-list |
| Login timing | Constant-time decoy hash for unknown users |
| Brute force | Per-identifier lockout via `CachePort` (5 failures / 15 min) |
| Hash upgrades | `needsRehash()` → transparent rehash on successful login |
| Central identity | Identity is GLOBAL (central `users`); repository pinned to the central connection |
| PII in logs | Exception context carries IDs only; audit pseudonymises identifiers |
| Credentials in transit | `password_hash` / `remember_token` never appear in any DTO/JSON |
| Audit | `AuditLogger` writes structured JSON for register/update/delete/login/lockout/rehash |

---

## Reliability: the transactional outbox

Integration events are **not** dispatched directly after commit (which can be
lost on a crash). Instead:

1. The service writes the event into `user_outbox` **inside the same
   transaction** as the state change (atomic).
2. `user:outbox:relay` (cron/supervised) reads pending rows and dispatches them
   to the `EventBus` — **at-least-once**, idempotency keyed by the event UUID.

```
register/update/delete ──tx──▶ [users row + user_outbox row]  COMMIT
                                            │
        cron: php cli user:outbox:relay ────▶ EventBus ──▶ subscribers
```

Consumers must dedupe on the event id (it may be redelivered after a crash
between dispatch and mark-dispatched).

---

## Installation & wiring

1. **Enable the plugin** (publishes config/, database/, resources/):

   ```bash
   hkm plugins enable User
   ```

2. **Register the Provider** in your project bootstrap:

   ```php
   // projects/<name>/bootstrap/app.php
   return $builder
       ->withModules([
           Plugins\Crypto\Provider::class,   // crypto.services (HashingPort)
           Plugins\View\Provider::class,     // view.rendering
           Plugins\User\Provider::class,     // user.management
       ])
       ->build();
   ```

3. **Ensure required capabilities are available:** `database.management`
   (DatabaseConnectionManagerContract — the repository pins to the central/default
   connection), `crypto.services` (HashingPort), `cache.redis` (CachePort),
   `view.rendering` (ViewRendererContract). The plugin declares these in
   `requires[]`, so boot fails fast if one is missing.

4. **Run migrations:**

   ```bash
   php cli migrate:run
   php cli db:seed --class=UserSeeder    # optional baseline admin
   ```

5. **Schedule the outbox relay** (e.g. cron every minute):

   ```cron
   * * * * * cd /app && php cli user:outbox:relay --limit=500
   ```

---

## Configuration

`.env` keys (all optional):

```ini
HASH_BCRYPT_COST=12        # bcrypt cost (crypto.services)
CSRF_BIND_COOKIE=csrf_bind # must match the CsrfTokenLayer bindCookie
CSRF_LIFETIME=43200        # must match the CsrfTokenLayer lifetime (seconds)
```

`module.json` declares `requires`, `routes`, `views`, `emits`, `commands`, and
`config[]`. Every env var the plugin reads is declared in `config[]` (boot fails
otherwise).

---

## Using it from another plugin

Depend on the **published contract**, never on internals. Declare the domain in
your `module.json`:

```jsonc
// plugins/Billing/module.json
{ "requires": ["user.management", "database.management"] }
```

Inject the contract in your Provider:

```php
use Plugins\User\API\Contracts\UserServiceContract;

$container->bind(InvoiceService::class, fn($c) => new InvoiceService(
    users: $c->make(UserServiceContract::class),   // resolvable because you require user.management
));
```

```php
final class InvoiceService
{
    public function __construct(private readonly UserServiceContract $users) {}

    public function billFor(string $userId): void
    {
        $user = $this->users->find($userId);     // ?UserDTO — primitives only
        if ($user === null) { /* … */ }
    }
}
```

React to user lifecycle events instead of polling — subscribe in `boot()`:

```php
public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $w, EventBus $events): void
{
    $events->subscribe('user.registered', SendWelcomeEmailListener::class);
    $events->subscribe('user.deleted',    PurgeUserDataListener::class);
}
```

The event payload is **primitives only** (`userId`, `username`, `email`,
`occurredAt`, `version`) — your module never needs the User plugin's value
objects.

> Scope isolation still applies: requiring `user.management` grants the **public
> contract** only. `UserRepository` and other `bindInternal` bindings throw
> `ScopeViolationException` if resolved cross-scope.

---

## Using it from a project

A project route can opt into the plugin without loading it app-wide:

```jsonc
// projects/<name>/proj.json
{ "routes": [
    { "method": "GET", "path": "/admin/users",
      "handler": "Projects\\Admin\\Http\\AdminUserController@index",
      "requires": ["user.management"], "filters": ["auth"] }
] }
```

```php
namespace Projects\Admin\Http;

use Plugins\User\API\Contracts\UserServiceContract;
use Plugins\User\API\DTOs\ListUsersQuery;
use Project\Http\Controllers\ApiController;

final class AdminUserController extends ApiController
{
    public function __construct(private readonly UserServiceContract $users) {}

    public function index(): Response
    {
        $page = $this->users->list(ListUsersQuery::fromRequest($this->resolveRequest()));
        return $this->paginated(array_map(fn($u) => $u->toArray(), $page->items),
                                total: count($page->items), page: 1, perPage: $page->limit);
    }
}
```

Project views override plugin views by default (project-first cascade); target a
specific plugin view with `user::users/index`.

---

## CLI

```bash
php cli user:outbox:relay            # relay up to 100 pending events
php cli user:outbox:relay --limit=500
```

Returns the number dispatched. Idempotent and safe to run concurrently (rows are
claimed via a status guard).

---

## Testing

The service depends on the `UserStore` and `OutboxPort` **interfaces** (DIP), so
it is fully unit-testable with in-memory fakes — no database:

```php
$svc = new UserService(
    repository:  new FakeUserStore(),
    transaction: new TransactionManager(new FakeDatabasePort()),
    collector:   new DomainEventCollector(),
    outbox:      new FakeOutbox(),
    hasher:      new FakeHasher(),
    identity:    Identity::asAdmin(),
    cache:       new FakeCache(),
    audit:       new AuditLogger('admin', fn($l) => null),
);
```

See `tests/Unit/Plugins/User/` (13 tests): registration, duplicate rejection,
weak-password rejection, authorization (self vs admin), update/delete event
emission, login lockout, and rehash-on-login. Run:

```bash
vendor/bin/phpunit tests/Unit/Plugins/User
```

---

## Extending the pattern

This plugin is a template. To build your own domain module the same way:

1. **module.json** — declare `solves`, `requires`, `exposes`, `routes`, `emits`,
   `config`. One module, one domain.
2. **Domain** — `final` entity with a **private constructor** + named
   constructors (`create`/`reconstitute`); value objects for every concept;
   record domain events in the aggregate. Zero external imports.
3. **API** — a published `…ServiceContract` interface + DTOs (validation in
   `fromRequest`) + primitives-only integration events.
4. **Application** — a service that owns the `TransactionManager`, collects
   domain events, writes integration events to an **outbox** in-tx, and does
   **authorization first**.
5. **Infrastructure** — repository (DatabasePort only, optimistic-locked;
   control-plane repos pin to the central connection), gateways (vendor SDK
   only), thin controllers (≤3 lines).
   Hide concretes behind **internal ports** so the service stays testable.
6. **Provider** — `register()` binds internals with `bindInternal` and the
   contract with `bind`; `boot()` registers hooks / CLI commands / event
   subscriptions.

Copy `plugins/User/` as a starting skeleton, rename the namespace, and replace
the domain.

---

*Part of the AlfacodeTeam PhpServicePlatform. See the root `CLAUDE.md` and
`docs/ai-context/` for framework-wide architecture.*
