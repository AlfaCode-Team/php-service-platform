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
| `SecurityFilters` | `http.security_filters` | HTTP pipeline stages (HMAC, Shield, RequireAuth, RateLimit) |
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
| `Session` | `session.management` | `SessionPort` — file/array handlers, flash, CSRF, lazy persist |
| `Cookie` | `http.cookies` | `CookieJar` — queued cookies, encrypt/decrypt via `EncryptionPort` |
| `RedisCache` | `cache.redis` | `CachePort` + `QueuePort` — ext-redis, in-memory fallback |

Activation: `Storage`, `View`, and `HttpClient` are **on-demand** (a consumer
declares `requires: ["storage.local"]` / `["view.rendering"]` / `["http.client"]`).
`Session`, `Cookie`, and
`RedisCache` are **essential** (registered every request via
`withEssentialModules` in `app/bootstrap/base.php`). `SecurityFilters` also
provides the `CorsStage` + `SecureHeadersStage` pipeline stages. See
`16_PLUGINS.md` and the MODULE ACTIVATION section of `CLAUDE.md`.

---

## Storage (local + S3)

`StoragePort` adapter. `STORAGE_DRIVER=local` (default) uses atomic file writes
under `STORAGE_ROOT` with HMAC-signed `temporaryUrl()`; `STORAGE_DRIVER=s3` uses
`league/flysystem-aws-s3-v3` (AWS S3 / DigitalOcean Spaces / Cloudflare R2 / MinIO)
with native pre-signed URLs. On-demand: a consuming module declares
`{ "requires": ["storage.local"] }`.

```php
$path = $storage->store($bytes, 'invoice.pdf', 'invoices/2026', 'private');
$url  = $storage->temporaryUrl($path, 600);
```

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
(`SESSION_DRIVER=file|array`), flash data, CSRF `token()`, `regenerate()`/
`invalidate()` for fixation defence, and **lazy persistence** — a fresh visitor
who never writes the session gets no file and no cookie (stateless API/bot traffic
stays clean). `StartSessionStage` (hooked `after.load`) opens it before modules
and persists + sets the cookie after, only when `shouldPersist()`.

> Apps must call `$session->regenerate()` after login (fixation defence). The
> kernel's CSRF layer is double-submit-cookie based and independent of `token()`.

## Cookie (essential)

`CookieJar` queues outgoing cookies flushed by `QueuedCookiesStage`; values are
encrypted via `EncryptionPort` (except an exempt list). Read incoming cookies with
`$jar->read($request, $name)` (auto-decrypts; exempt cookies returned raw).
Encryption is only meaningful with `APP_KEY` set — the kernel hard-fails at boot
outside `local`/`testing` when it is missing.

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

The 0.3 filters rebuilt as `HttpStageContract` stages, registered as pipeline
hooks. Each inspects the resolved request path/method and only enforces on its
configured scope (router-driven), otherwise calls `$next`.

| Stage | Slot | Config |
|---|---|---|
| `HmacSignedStage` | after.security | `HMAC_PROTECTED_PREFIX`, `REQUEST_SIGNING_SECRET`, `HMAC_MAX_SKEW` |
| `RequireAuthStage` | after.security | `AUTH_PROTECTED_PATHS` (exact / `prefix/*` / `*` segment) |
| `ShieldStage` | after.security | `SHIELD_RULES` (`/path=role:admin;/x=perm:y`) |
| `ApiRateLimitStage` | after.load | `RATE_LIMIT_PREFIX/MAX/WINDOW` (uses `CachePort`) |

`RequireAuthStage` is the answer to "require auth on `/profile/settings` only":
the auth layer attaches Identity globally; this stage decides which paths demand it.

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
