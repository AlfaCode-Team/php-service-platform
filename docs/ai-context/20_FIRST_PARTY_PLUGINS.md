# First-Party Plugins — Ported / Built Capabilities

These plugins live under `plugins/` (namespace `Plugins\`) and were added to give
the GDA kernel capabilities it intentionally did not ship with. Each follows the
plugin convention in `16_PLUGINS.md`: a `module.json`, a `Provider`, and the GDA
layer layout. Register a plugin by adding `Plugins\{Name}\Provider::class` to a
project bootstrap (most are already in `app/bootstrap/base.php` or
`projects/admin/bootstrap/app.php`).

| Plugin | solves | Exposes / provides |
|---|---|---|
| `Authorization` | `authorization.policy` | `AuthorizationServiceContract` (Casbin RBAC/ABAC) |
| `Auth` | `auth.identity` | `AuthServiceContract` + JWT/PAT SecurityLayers |
| `SocialAuth` | `auth.social` | `SocialAuthServiceContract` (OAuth1/OAuth2) |
| `SecurityFilters` | `http.security_filters` | global hooks (CORS, SecureHeaders) + route-filter aliases (`auth`, `throttle`, `hmac`, `shield`) |
| `Crypto` | `crypto.services` | `EncryptionPort` + `HashingPort` adapters |
| `Validation` | — (library) | `Validator` rules engine |
| `I18n` | `i18n.translation` | `Translator` |
| `Support` | — (library) | `Collection`, `Arr`, `Str`, `Resource`, `collect()` |
| `Mail` | `mail.smtp` | `MailPort` SMTP adapter |
| `Pageflow` | `http.pageflow` | `PageflowResponder` (Inertia-style SPA bridge) |
| `DevTools` | `dev.tooling` | `make:*`, `module:list/info`, `routes:list`, `project:list` |
| `Storage` | `storage.local` | `StoragePort` — local disk + S3 driver (Flysystem), signed URLs |
| `View` | `view.rendering` | `ViewRendererContract` — PHP template engine (layouts, sections, decorators) |
| `HttpClient` | `http.client` | `HttpClientPort` — cURL client, fluent builder, multipart |
| `Session` | `session.management` | `SessionPort` — file/array/cookie handlers, flash, CSRF, lazy persist |
| `Cookie` | `http.cookies` | `CookieJar` — queued cookies, encrypt/decrypt via `EncryptionPort` |
| `RedisCache` | `cache.redis` | `CachePort` + `QueuePort` — ext-redis, in-memory fallback |
| `Tenancy` | `tenancy.routing` | `TenantRegistryContract` + `TenantConnectionResolverContract` + `MembershipServiceContract` + `InvitationServiceContract` + `RefreshTokenServiceContract` — database-per-tenant routing + selection/invitation/refresh flows |

Activation: `Storage`, `View`, and `HttpClient` are **on-demand** (a consumer
declares `requires: ["storage.local"]` / `["view.rendering"]` / `["http.client"]`).
`Session`, `Cookie`, and
`RedisCache` are **essential** (registered every request via
`withEssentialModules` in `app/bootstrap/base.php`). `SecurityFilters` runs
`CorsStage` + `SecureHeadersStage` as global hooks and registers the `auth` /
`throttle` / `hmac` / `shield` route-filter aliases (opt in per route via
`"filters": [...]`). See `16_PLUGINS.md`, the SecurityFilters section below, and
the "TWO WAYS A STAGE RUNS" + MODULE ACTIVATION sections of `CLAUDE.md`.

---

## Storage (local + S3)

`StoragePort` adapter. `STORAGE_DRIVER=local` (default) uses atomic, fsync'd file
writes under `STORAGE_ROOT` (short-write detection guards against silent
disk-full corruption) with HMAC-signed `temporaryUrl()`; `STORAGE_DRIVER=s3` uses
`league/flysystem-aws-s3-v3` (AWS S3 / DigitalOcean Spaces / Cloudflare R2 / MinIO)
with native pre-signed URLs. On-demand: a consuming module declares
`{ "requires": ["storage.local"] }`.

**S3 credentials:** leave `STORAGE_S3_KEY` empty on EC2/ECS/EKS — `fromConfig()`
then omits static credentials so the AWS default provider chain (IAM
instance/task roles, env, SSO) resolves them. Only set the key/secret for
non-AWS providers or local dev. The adapter is bound as a request-scoped
**singleton**, so the `S3Client` is built once per request, not per resolution.

**Configuration** is env-driven through `config/storage.php`, read via the
`storage_config()` helper (dotted access; a project copy at
`projects/<name>/config/storage.php` overrides the plugin default):

```php
storage_config('driver');      // 'local' | 's3'
storage_config('local.root');  // STORAGE_ROOT
storage_config('s3.bucket');   // STORAGE_S3_BUCKET
```

**Streaming** (large blobs, no full in-memory buffer):

```php
$path = $storage->store($bytes, 'invoice.pdf', 'invoices/2026', 'private');
$url  = $storage->temporaryUrl($path, 600);

$storage->storeStream($readable, 'export.csv', 'exports');   // stream → storage
$handle = $storage->readStream('exports/export.csv');        // storage → stream (caller closes)
```

Env keys: `STORAGE_DRIVER`, `STORAGE_ROOT`, `STORAGE_URL_BASE`,
`STORAGE_URL_SECRET`, `STORAGE_S3_BUCKET`, `STORAGE_S3_REGION`, `STORAGE_S3_KEY`,
`STORAGE_S3_SECRET`, `STORAGE_S3_ENDPOINT`, `STORAGE_S3_PATH_STYLE`.

## View (PHP templates)

`ViewRendererContract` — a PHP template engine ported from CodeIgniter 4 and
rebuilt to GDA rules: **no globals** (view paths, extensions, decorators and the
HTML escaper are all constructor-injected; the engine reads no `config()`/`kernel()`),
**request-scoped** (bound per request, so its mutable template data never leaks
across requests under OpenSwoole), and no file-locator dependency (views resolve
against the injected paths). Supports data binding with optional escaping,
layouts (`$options['layout']` or `extend()`/`section()`), section rendering,
includes and output decorators. On-demand: `{ "requires": ["view.rendering"] }`.

`Plugins\View\Infrastructure\SidebarManager` ships alongside as a navigation-HTML
builder (instance-scoped icon cache — never `static`).

Config (env; `VIEW_PATHS` unset → defaults to `<project>/resources/views`):
`VIEW_PATHS` (colon/comma-separated dirs), `VIEW_EXTENSIONS` (default `php`),
`VIEW_SAVE_DATA` (persist data across `render()` calls).

```php
// Controller injects ViewRendererContract (its module requires "view.rendering"):
return Response::html(
    $this->view->setVar('name', $user->name)         // pass raw…
               ->render('welcome', ['layout' => 'layouts/app'])
);
// Escape ONCE: either pre-escape via setVar(..., 'html') AND echo raw in the
// template, OR pass raw and escape in the template — never both (double-escapes).
```

## HttpClient (outbound cURL)

`HttpClientPort` adapter for Gateways. Dependency-free cURL with an immutable
fluent builder (`pending()`), retry/backoff, and multipart uploads. Vendor errors
are translated to `GatewayException`. On-demand: `{ "requires": ["http.client"] }`.

```php
$res = $client->pending()->acceptJson()->withToken($t)->post($url, $payload);
if ($res->ok()) { $data = $res->json(); }
$client->pending()->asMultipart()->attach('file', $bytes, 'a.png')->post($url);
```

## Session (essential)

`SessionPort` adapter with native `\SessionHandlerInterface` handlers
(`SESSION_DRIVER=file|array|cookie`), flash data, CSRF `token()`, `regenerate()`/
`invalidate()` for fixation defence, and **lazy persistence** — a fresh visitor
who never writes the session gets no file and no cookie (stateless API/bot traffic
stays clean). `StartSessionStage` (hooked `after.load`) opens it before modules
and persists + sets the cookie after, only when `shouldPersist()`.

> Apps must call `$session->regenerate()` after login (fixation defence). The
> kernel's CSRF layer is double-submit-cookie based and independent of `token()`.

### Drivers

| `SESSION_DRIVER` | Storage | Notes |
|---|---|---|
| `file` (default) | one file per session under `var/sessions/` | server-side; `SESSION_PATH` overrides the dir |
| `array` | in-memory (per process) | tests / CLI / stateless contexts |
| `cookie` | **in the session cookie itself** | stateless & horizontally-scalable — no server store |

### Cookie driver — stateless, encrypted/signed sessions

`CookieSessionHandler` carries the whole serialized attribute bag inside the
session cookie, so nothing is stored server-side (ideal for multi-node deploys).
Defence in depth, all env-driven:

- **Protection** — encrypted via `EncryptionPort` when `APP_KEY`/Crypto is present
  (confidential + authenticated); otherwise **HMAC-SHA256 signed** with
  `SESSION_SIGNING_KEY` (falls back to `APP_KEY`) — readable but tamper-evident,
  verified with `hash_equals()`.
- **Timeouts** — `SESSION_LIFETIME` (absolute, never extended by re-saving) and
  `SESSION_IDLE_TIMEOUT` (sliding), both enforced server-side on read.
- **Fingerprint binding** — `SESSION_COOKIE_FINGERPRINT=off|ua|ip|ua,ip` ties the
  session to a hashed client fingerprint. `ua` survives IP changes (safe for
  mobile); `ip`/`ua,ip` are stricter anti-theft.
- **Compression** — `SESSION_COOKIE_COMPRESS` deflates data above N bytes to fit
  more under the ~4 KB cookie limit; `SESSION_COOKIE_MAX_BYTES` drops an oversized
  cookie (and expires any stale one) rather than emit an invalid `Set-Cookie`.
- **Hard guards** — `SESSION_COOKIE_REQUIRE_AUTH` (default on) fails boot unless
  signed or encrypted; `SESSION_COOKIE_REQUIRE_ENCRYPTION` fails boot unless
  *encrypted* (blocks the signed-but-readable mode for confidential data).
- **Cookie attributes** — `SESSION_SECURE=auto|true|false`, plus
  `SESSION_COOKIE_PATH` / `SESSION_COOKIE_DOMAIN`.
- Binary-safe regardless of `SESSION_SERIALIZATION` (`json` default | `php`).

> Keep cookie sessions small (ids/flags/CSRF) — they ride on every request and are
> capped at ~4 KB. Use `file` (or a Redis driver) for large session state.

## Cookie (essential)

`CookieJar` queues outgoing cookies flushed by `QueuedCookiesStage`; values are
encrypted via `EncryptionPort` (except an exempt list). Read incoming cookies with
`$jar->read($request, $name)` (auto-decrypts; exempt cookies returned raw).
Encryption is only meaningful with `APP_KEY` set — the kernel hard-fails at boot
outside `local`/`testing` when it is missing.

**Config — `plugins/Cookie/config/cookie.php` (env-driven; project override wins).**
A project may copy it to `projects/<name>/config/cookie.php`; `cookie_config()`
resolves the project file first (via `Paths::config()`), else the plugin default.
Every value reads from `.env`:

| Env | Key | Default |
|---|---|---|
| `COOKIE_LIFETIME` (minutes) | `lifetime` | `120` |
| `COOKIE_PATH` | `path` | `/` |
| `COOKIE_DOMAIN` | `domain` | `null` (bind to issuing host) |
| `COOKIE_SECURE` | `secure` | `true` (set `false` for local http://) |
| `COOKIE_HTTP_ONLY` | `http_only` | `true` |
| `COOKIE_SAME_SITE` | `same_site` | `Lax` |
| `COOKIE_ENCRYPT_EXEMPT` (comma-separated) | `encrypt_exempt` | `[]` |

`CookieJar::queue()` attributes are nullable — omitted ones fall back to these
defaults, so callers usually pass only name + value.

**Encryption exemptions (`encrypt_exempt`).** Names listed here are written AND
read as plaintext — `CookieJar` skips both `encryptString()` on flush and
`decryptString()` on `read()` for them. The final list is a base array declared
in `config/cookie.php` MERGED with the comma-separated `COOKIE_ENCRYPT_EXEMPT`
env var (de-duplicated), so deployments can add names without editing code.
Exempt a cookie when its raw value must stay stable and readable as-is:

- a JS-readable flag (theme, locale) the front-end reads directly; or
- an opaque session/binding cookie a **pre-load security layer** reads raw — e.g.
  `CsrfTokenLayer`'s `bindCookie`. Encryption rotates the ciphertext on every
  response (random IV), which would break that binding; exempting it keeps the
  value byte-stable across requests. See [CSRF guide](21_CSRF.md).

**Helpers (`plugins/Cookie/Support/helpers.php`, autoloaded):**

```php
cookie_config();              // full config array (cached per process)
cookie_config('same_site');   // single key
$jar->queue(...cookie('cart', $id, minutes: 30));            // spread into queue()
Response::json($d)->withCookie(...cookie('seen', '1'));      // or into withCookie()
```

`cookie()` returns a spread-ready attribute array (keys match both
`CookieJar::queue()` and `Response::withCookie()`); `maxAge` is in seconds.

> `.env` gotcha: an empty value followed by an inline comment (`COOKIE_DOMAIN=  # note`)
> resolves to empty — `LoadEnvironment` treats a comment-only value as `''`. Put
> comments on their OWN line to avoid surprises with non-empty values.

## RedisCache (essential)

`CachePort` + `QueuePort` on ext-redis (one shared lazy connection). Numbers are
stored raw so `increment()`/`set()`/`get()` interoperate (the rate limiter relies
on this); everything else is serialized. `deletePattern()` uses non-blocking SCAN.
Only binds when `REDIS_HOST` is set (else the in-memory `CachePort` stays).
`REDIS_PERSISTENT=true` enables `pconnect` reuse (FPM only — keep off on Swoole).

---

## Authorization (Casbin)

Casbin policy engine wrapped for GDA. Policy storage goes through `DatabasePort`
via `DatabasePolicyAdapter` (table `casbin_rule`); the `Enforcer` is an internal
binding and only `AuthorizationServiceContract` is exposed.

```php
$authz->allows($userId, 'invoice:42', 'edit');   // bool
$authz->assignRole($userId, 'admin', $tenantId);
$authz->grant('admin', 'invoice', 'edit');
```

Model config: `plugins/Authorization/config/rbac_model.conf` (override with
`AUTHZ_MODEL_PATH`). Run the bundled migration to create `casbin_rule`.

## Auth (JWT + Personal Access Tokens)

Credential **issuance** is `AuthServiceContract` (`issueJwt`, `createPersonalAccessToken`,
`hashPassword`/`verifyPassword` via `HashingPort`). Credential **verification** is
done by SecurityLayers wired into the kernel `withSecurity([...])` chain:

- `JwtAuthLayer(secret, algo)` — validates `Authorization: Bearer <jwt>`.
- `PersonalAccessTokenLayer(databasePort)` — validates DB-backed `<id.secret>` tokens.

No header → anonymous (public routes still work). Invalid token → `deny(401)`.
PATs are looked up by deterministic `sha256` (passwords use bcrypt via `HashingPort`).

## SocialAuth (OAuth)

Ported OAuth providers (GitHub, Google, Facebook, GitLab, Bitbucket, LinkedIn,
Slack, X). A small compat layer (`Socialite/Http`, `Socialite/Support`) lets the
stateful OAuth flow run inside the stateless kernel. OAuth2 drivers work out of
the box; the Twitter OAuth1 driver also needs `league/oauth1-client` + `phpseclib`.

```php
$social->redirectUrl('github');                 // start
$social->userFromCallback('github', $request);  // resolve user
```

## SecurityFilters (HTTP stages)

The 0.3 filters rebuilt as `HttpStageContract` stages. CORS + SecureHeaders run as
GLOBAL pipeline hooks (every request); HMAC, auth, Shield and the rate limiter are
exposed as DECLARATIVE route-filter aliases that a route opts into by name. A stage
runs through exactly ONE mechanism — never both (double-registering double-runs it).

**Global hooks** (registered in `Provider::boot()`, run on every request):

| Stage | Slot | Config |
|---|---|---|
| `CorsStage` | after.security | `CORS_ALLOWED_ORIGINS/METHODS/HEADERS`, `CORS_ALLOW_CREDENTIALS`, `CORS_MAX_AGE` |
| `SecureHeadersStage` | after.execute | `CONTENT_SECURITY_POLICY`, `HSTS_MAX_AGE` |

**Route-filter aliases** (registered via `$http->filter(...)`; a route opts in with
`"filters": [...]` in module.json / proj.json):

| Alias | Stage | Config |
|---|---|---|
| `hmac` | `HmacSignedStage` | `HMAC_PROTECTED_PREFIX`, `REQUEST_SIGNING_SECRET`, `HMAC_MAX_SKEW` |
| `auth` | `RequireAuthStage` | also honours `AUTH_PROTECTED_PATHS` (exact / `prefix/*` / `*` segment) |
| `shield` | `ShieldStage` | `SHIELD_RULES` (`/path=role:admin;/x=perm:y`) |
| `throttle` | `ApiRateLimitStage` | `RATE_LIMIT_PREFIX/MAX/WINDOW` (uses `CachePort`); `"throttle:max,window"` args |

```jsonc
// require auth + throttle on one route, declaratively
{ "method": "POST", "path": "/api/tasks", "handler": "...@create",
  "filters": ["auth", "throttle:60,1"] }
```

`RequireAuthStage` enforces when EITHER the route declared the `auth` filter OR the
path is in `AUTH_PROTECTED_PATHS` — the auth layer attaches Identity globally, this
stage decides which routes demand it. See CLAUDE.md "TWO WAYS A STAGE RUNS" for the
hook-vs-filter model and `RouteFilterStage` / `FilterRegistry` internals.

## Crypto (kernel ports)

Adds two **kernel ports** the framework was missing, with adapters:

- `EncryptionPort` → `AesEncrypter` — authenticated AES-256-GCM with key rotation.
- `HashingPort` → `PasswordHasher` — bcrypt/argon2 over `password_*`.

Wired in `app/bootstrap/base.php` from `APP_KEY` / `APP_KEY_PREVIOUS` /
`HASH_BCRYPT_COST`. **Set a real 32-byte `APP_KEY` in production.**

## Validation

Dependency-free rules engine that throws the kernel `ValidationException`
(field → messages, the standard 422 shape). Optional `Translator` for localized
messages.

```php
Validator::make($request->all(), [
    'email'    => 'required|email',
    'age'      => 'required|integer|min:18',
    'password' => 'required|min:8|confirmed',
])->validate(); // returns validated data or throws
```

Rules: `required, nullable, string, integer, numeric, boolean, array, email, url,
min, max, between, in, regex, same, different, confirmed`.

## I18n

File-based `Translator`: `lang/{locale}/{group}.php`, dotted keys, `:placeholder`
substitution, locale→fallback→key resolution, path-traversal guarded.
Config: `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_LANG_PATH`.

## Support

`Collection` (fluent, immutable-friendly), `Arr`, `Str`, and `Resource` /
`ResourceCollection` (API transformers). `collect()` helper autoloaded.

```php
collect($rows)->map(...)->where('active', true)->pluck('id')->all();
UserResource::collection($users)->toArray();
```

## Mail (SMTP)

`SmtpMailer` implements `MailPort` over a dependency-free `SmtpTransport`
(STARTTLS/SSL, AUTH LOGIN). Bound only when `SMTP_HOST` is set, so unconfigured
projects are unaffected. Renders PHP-template views or inline HTML.

## Pageflow (SPA bridge)

Server side of an Inertia-style protocol. `PageflowResponder::render($request,
$component, $props)` returns JSON for `X-Pageflow` XHR navigations or an HTML
shell (mounting into `{{app}}`) on first load, and honours partial reloads.
`PageflowVersionStage` returns `409 + X-Pageflow-Location` on stale assets.
The React client lives in the top-level `frontend/` workspace.

## SiteSEO (`seo.management`, on-demand)

Full SEO toolkit + Project-layer support. `requires: ["http.client"]`. Published
`SeoServiceContract`: `openGraph()`, `schema()`, `sitemap()`, `robots()`,
`pingSitemap()`, `indexNow(host,key,keyLocation,urls,endpoints,dryRun)`
(auto-batches 10k, lazy iterable), `indexNowChunks()`. All outbound HTTP goes
through `Infrastructure/Gateways/SearchEngineGateway` (`HttpClientPort`) — never
raw cURL. The toolkit value classes (`OpenGraph`, `Schema`, `Sitemap*`,
`RobotsTxtEditor`) autoload directly, so building sitemaps / OG / JSON-LD needs
NO module load; only ping + IndexNow do (they hit the network).

Project-layer helpers (`Project\Support\Seo\`, reusable & DI-free):

- `RouteCatalog` — public static GET pages from the route manifest (drops
  `{param}`, auth-gated, `/api`, SEO endpoints).
- `SitemapGenerator` — small/route-derived `<urlset>` (≤30k); `toXml()`/`save()`.
- `SitemapStreamWriter` — **enterprise**: streams an `iterable` to split child
  files + index at **O(1) memory** (no DOM), 50k split, optional gzip. For
  millions of URLs (verified flat memory to 1M+).
- `SitemapUrlProvider` + `SitemapSource` — expand dynamic routes (`/blog/{slug}`)
  from the DB with a keyset-cursor generator; `uncoveredDynamicRoutes()` guards
  silent omissions.
- `RichGraph` — Schema.org JSON-LD `@graph` for Google rich results (org →
  website[SearchAction] → webPage → breadcrumb → content node, linked by `@id`).
  Content nodes: article/newsArticle/blogPosting, product (offer+rating+review),
  book, course (syllabus), realEstate (lease), pageantEdition/awardEdition/
  contestant (Event+Person), faq.
- `SeoHead` — full `<head>`: title, description, **canonical**, **robots**,
  **hreflang**/x-default, plus attached OG + JSON-LD.
- `IndexNowKey` — key/keyLocation value object.

Controller traits (`Project\Http\Controllers\Concerns\`): `InteractsWithSeo`
(siteBaseUrl, sitemap, openGraph, ogImage, richGraph, robots) and
`InteractsWithGraphSeo` (adds `graph()` + `seoHead()`).

Background indexing: job `seo.indexnow` (`IndexNowJob`, queue `indexing`,
declared in `module.json` `jobs[]`, bound in `Provider::register()`) submits one
≤10k batch; dispatch by chunking a URL stream and `QueuePort::push()` per batch
(`FileQueue` in `Project\Infrastructure\` is the no-Redis fallback). Index-on-
publish: emit `UrlPublishedIntegrationEvent` after commit → SEO module subscribes
`EnqueueIndexNowListener` (`Provider::boot()`) which enqueues. The EventBus
resolves listeners from the CoreContainer (`has()` bound-only), so the **project
binds the listener with its `QueuePort`** in `bootstrap/app.php`. Env:
`INDEXNOW_KEY` (listener no-ops without it), `INDEXNOW_LIVE`.

`NOTE` the toolkit had two real bugs fixed during integration: `Schema` now emits
a proper multi-node `@graph` (was serializing only `things[0]`), and the Twitter
card no longer leaks `og:image:*` keys when a structured image is attached.

## Tenancy (multi-tenant control plane)

`solves: tenancy.routing`, `requires: ["database.management"]`, **essential**.
Database-per-tenant isolation layered on `plugins/Database`'s `ConnectionManager`.

Two planes: a **central (control) DB** holds `users`, `tenants`, `user_tenants`
(+ optional invitations/refresh-tokens/audit); each **tenant has its own DB**
containing only business domain (no auth, no `tenant_id` column — the database is
the boundary). User references inside a tenant DB store the central
`users.user_id` ULID as an opaque value (no cross-DB FK).

Flow: the Auth layer mints a tenant-scoped `Identity` (JWT `tnt` claim →
`Identity.tenantId`) after the user selects a tenant, re-checking `user_tenants`
each request so a revoked membership drops access before the token expires.
`TenantContextStage` (hooked at `after.load`) reads `Identity.tenantId`, asks
`TenantConnectionResolver` for that tenant's `DatabasePort`, and **rebinds
`DatabasePort` in the request container** — every repository then transparently
talks to the tenant DB.

- **`TenantRegistry`** — cached reads of central `tenants` (DatabasePort-only,
  reads the `ConnectionManager` default = central connection).
- **`TenantConnectionResolver`** — `tenant_id → DatabasePort`; registers a named
  `tenant:<id>` connection (password decrypted via `EncryptionPort` at connect
  time only). **Fail-closed**: unknown/suspended/deleted/unreachable → throw,
  never falls back to another tenant or central. Per-tenant **circuit breaker**
  (`TENANCY_BREAKER_THRESHOLD`/`TENANCY_BREAKER_COOLDOWN`) isolates one dead
  tenant DB from the fleet.
- **Swoole-safe**: tenant `DatabasePort` is bound into the per-request
  `ModuleContainer` (discarded on `reset()`); tenant id rides on the immutable
  `Request`/`Identity`, never a static or `CoreContainer`. For cross-request
  pooling, bind `ConnectionManager` + resolver into the `CoreContainer` in
  bootstrap (see the plugin README) and LRU-evict idle tenant connections.
- **CLI**: `tenants:create` (registry row → CREATE DATABASE → template migrate →
  activate, with compensating `provisioning` status) and `tenants:migrate`
  (resumable, failure-isolated fleet migrator; each tenant DB keeps its own
  `let_migrations` table; central `tenants.schema_version` mirrors drift).
- **Tenant template** migrations live in `plugins/Tenancy/database/tenant-template/`
  (override via `TENANCY_TEMPLATE_PATH`). Use expand→migrate→contract for
  destructive changes and canary waves across the fleet.
- **Tenant-selection flow** (`MembershipServiceContract`, requires `auth.identity`):
  `GET /api/me/tenants` lists active seats; `POST /api/tenants/{tenantId}/select`
  re-verifies the membership against central `user_tenants` (never trusts a
  client-supplied id), mints a tenant-scoped token via the Auth module (`tnt`
  claim), and audits `tenant.switch`. `TENANCY_TOKEN_TTL` sets the scoped-token
  lifetime. A revoked seat fails selection (`403`, audited `tenant.switch_denied`)
  and loses access on an already-issued token via the per-request re-check.
- **Control-plane tables** (central migrations): `tenants`, `user_tenants`,
  `tenant_invitations` (email onboarding, hashed token), `refresh_tokens`
  (revocable, hash-only), `audit_log` (append-only).
- **Invitations** (`InvitationServiceContract`): `invite()` returns a one-time
  token (hash stored); `accept()` requires the user's verified email to match,
  creates/activates the seat (idempotent), audits `member.join`; `revoke()`.
- **Refresh tokens** (`RefreshTokenServiceContract`): `issue()` / `rotate()` /
  `revoke()` / `revokeAllForUser()`. Rotation is one-time-use (revokes the
  presented token), re-checks `user_tenants` for scoped tokens, and mints a
  paired access JWT. Tunable via `TENANCY_REFRESH_TTL` / `TENANCY_ACCESS_TTL`.

Env: `TENANCY_MODE` (`legacy|dual-write|tenant` migration phases),
`TENANCY_REGISTRY_TTL`, `TENANCY_BREAKER_THRESHOLD`, `TENANCY_BREAKER_COOLDOWN`,
`TENANCY_TEMPLATE_PATH`. Full guide: `plugins/Tenancy/README.md`.

## DevTools (CLI)

`make:plugin`, `make:service` (GDA scaffolding), plus introspection that reads
`module.json` as the source of truth: `module:list`, `module:info <name>`,
`routes:list` (with collision detection), `project:list`.

---

## Tests

Unit tests for the new plugins live under `tests/Unit/Plugins/` (Crypto,
Validation, I18n, Support, Pageflow). Run `vendor/bin/phpunit`.
