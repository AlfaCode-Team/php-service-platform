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
| `COOKIE_ENCRYPT_EXEMPT` | `encrypt_exempt` | `[]` |

`CookieJar::queue()` attributes are nullable — omitted ones fall back to these
defaults, so callers usually pass only name + value.

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

## DevTools (CLI)

`make:plugin`, `make:service` (GDA scaffolding), plus introspection that reads
`module.json` as the source of truth: `module:list`, `module:info <name>`,
`routes:list` (with collision detection), `project:list`.

---

## Tests

Unit tests for the new plugins live under `tests/Unit/Plugins/` (Crypto,
Validation, I18n, Support, Pageflow). Run `vendor/bin/phpunit`.
