# Tenancy — Multi-Tenant Control Plane

Database-per-tenant routing for the AlfacodeTeam PhpServicePlatform. Maps the
authenticated `Identity.tenantId` to an **isolated tenant database** and rebinds
`DatabasePort` per request, so every repository transparently talks to the
correct tenant DB. Built on top of `plugins/Database`'s `ConnectionManager`.

- **solves:** `tenancy.routing`
- **requires:** `database.management`, `auth.identity`
- **exposes:** `TenantRegistryContract`, `TenantConnectionResolverContract`, `MembershipServiceContract`, `InvitationServiceContract`, `RefreshTokenServiceContract`

## Two planes

| Plane | DB | Tables |
|---|---|---|
| Control (central) | one, always connected | `users`, `tenants`, `user_tenants` (+ invitations/refresh/audit) |
| Data (per tenant)  | one per tenant, on demand | pure business domain — `projects`, `tasks`, … **no auth, no `tenant_id` column** |

## Wiring

1. **Run central migrations** (against the central connection):
   ```
   hkm migrate:run        # creates tenants, user_tenants (users lives in plugins/User)
   ```

2. **Register as an ESSENTIAL module** so every request is routed. In
   `projects/<name>/bootstrap/app.php`:
   ```php
   ->withEssentialModules([
       \Plugins\Database\Provider::class,   // database.management (required dep)
       \Plugins\Tenancy\Provider::class,    // tenancy.routing
       // ... Crypto (EncryptionPort), RedisCache (CachePort) must also be available
   ])
   ```
   `EncryptionPort` (Crypto) and `CachePort` (RedisCache) are dependencies of the
   resolver/registry — ensure both are wired.

3. **Mint a tenant-scoped Identity** in your Auth layer. After the user selects a
   tenant, re-check `user_tenants` and put the tenant in the JWT `tnt` claim; the
   Auth security layer sets `Identity.tenantId` from it. `TenantContextStage`
   (registered at `after.load`) does the rest.

   > The membership re-check on every request lives in the Auth layer, not here —
   > a revoked `user_tenants` row must drop access before the JWT expires.

## Control-plane tables

Central migrations (run on the central connection via `hkm migrate:run`):

| Table | Role |
|---|---|
| `tenants` | registry → connection coordinates (password encrypted) |
| `user_tenants` | M:N membership: user ↔ tenant + role + status |
| `tenant_invitations` | email onboarding; SHA-256 token only; converts to a membership on accept |
| `refresh_tokens` | revocable long-lived counterpart to short access JWTs; hash only |
| `audit_log` | append-only trail (login, `tenant.switch`, `tenant.create`, …) |

## Tenant-selection flow (`MembershipServiceContract`)

Turns an authenticated but *unscoped* user into a tenant-scoped session. Exposed
as routes (both behind the `auth` filter):

```
GET  /api/me/tenants                 → the tenant picker (active seats only)
POST /api/tenants/{tenantId}/select  → re-mint a tenant-scoped token
```

`selectTenant()` **re-verifies** the membership against central `user_tenants`
(never trusts a client-supplied tenant id), then mints a token via the Auth
module with the `tnt` claim set, and audits `tenant.switch`:

```php
$selection = $memberships->selectTenant($identity->userId, $tenantId, $request->ip());
// → { token, tokenType: "Bearer", tenantId, role, expiresIn }
```

The client sends the returned token on subsequent requests; `TenantContextStage`
routes them to the tenant database. A revoked/suspended seat fails `selectTenant`
with `403` (audited `tenant.switch_denied`) and — because the Auth layer re-checks
membership per request — also loses access on an already-issued token before it
expires. `TENANCY_TOKEN_TTL` (default 3600s) sets the scoped-token lifetime.

## Invitations (`InvitationServiceContract`)

Email-based onboarding that decouples "invited" from "has an account".

```php
$res = $invitations->invite($tenantId, 'alice@example.com', 'member', $inviterUserId);
// → InvitationResult{ token, … }  — embed $res->token in the emailed accept link (shown ONCE)

$tenantId = $invitations->accept($rawToken, $identity->userId, $userVerifiedEmail, $ip);
// validates (pending, not expired, email matches), creates/activates the user_tenants
// seat (idempotent), marks the invite accepted, audits member.join.

$invitations->revoke($rawToken);
```

Only the SHA-256 of the token is stored. `accept()` REQUIRES the authenticated
user's verified email to match the invited address (an invite for alice@ cannot
be claimed by bob@).

Wired endpoint (behind the `auth` filter; the email is read from the User
identity store, never the request body):

```
POST /api/invitations/accept   { "token": "…" }   → { "tenantId": "…" }
```

This is why Tenancy `requires: ["user.management"]` — `InvitationController`
resolves the caller's verified email via `UserServiceContract`.

## Refresh tokens (`RefreshTokenServiceContract`)

Revocable long-lived sessions paired with the short access JWT.

```php
$issued = $refreshTokens->issue($userId, $tenantId, $device, $ip);   // raw token shown once
$rot    = $refreshTokens->rotate($rawToken, $ip);
// → RefreshRotation{ accessToken, expiresIn, refreshToken, refreshExpiresAt, tenantId, role }

$refreshTokens->revoke($rawToken);          // logout this session
$refreshTokens->revokeAllForUser($userId);  // logout everywhere
```

Rotation is **one-time-use with reuse detection**. Each login roots a token
**family** (`family_id`); every rotated token inherits it. `rotate()` revokes the
presented token via an **atomic** conditional UPDATE — only the caller that wins
the revoke proceeds. Replaying an already-revoked token (a captured/stolen token)
or losing a concurrent rotation race is treated as **reuse**: the entire family
is revoked and `auth.refresh_reuse_detected` is audited, so a leaked token
cannot be used to fork a live session. Rotation also mints a fresh access JWT in
the same call, and — for a tenant-scoped token — **re-checks `user_tenants`** so a
revoked seat can't refresh back in. Tunable via `TENANCY_REFRESH_TTL` (default
30d) and `TENANCY_ACCESS_TTL` (default 15m).

Wired endpoints (UNAUTHENTICATED — the refresh token in the body is the credential):

```
POST /api/auth/refresh   { "token": "…" }   → { accessToken, expiresIn, refreshToken, refreshExpiresAt, tenantId, role }
POST /api/auth/logout    { "token": "…" }   → 204  (revoke this session)
```

`issue()` (at login / after `selectTenant`) is called from your Auth flow — there
is no public "mint a refresh token" endpoint, by design.

## Provisioning & migrations

```
hkm tenants:create --name="Acme" --slug=acme \
    --db-name=tnt_acme --db-user=acme --db-password=secret \
    --db-host=127.0.0.1 --db-port=3306

hkm tenants:migrate                 # apply template migrations to all active tenants
hkm tenants:migrate --tenant=<id>   # one tenant
hkm tenants:migrate --pretend       # print SQL, change nothing
```

The tenant template lives in `database/tenant-template/`. Override with
`TENANCY_TEMPLATE_PATH` or `--template`. Each tenant DB keeps its own
`let_migrations` table; the central `tenants.schema_version` mirrors the latest
applied batch for fleet-wide drift visibility. A failing tenant is skipped, not
fatal — the run is resumable.

## Isolation guarantees

- **Fail closed.** Unknown / suspended / deleted / unreachable tenant → throw.
  Never falls back to another tenant or to central. Status is re-validated on
  **every** request (the registry is cache-backed, so it's cheap) — including
  when the connection is already warm in a long-lived worker — so a suspension or
  deletion takes effect within `TENANCY_REGISTRY_TTL`, not "after the next worker
  restart". A control-plane change can call `resolver->invalidate($tenantId)` to
  drop the warm handle + cached row immediately.
- **Per-tenant circuit breaker.** After `TENANCY_BREAKER_THRESHOLD` consecutive
  **connectivity** failures within `TENANCY_BREAKER_WINDOW` seconds, the tenant
  fast-fails for `TENANCY_BREAKER_COOLDOWN` seconds, isolating one dead tenant DB
  from the fleet. Only genuine connection faults (`ConnectionException` with a
  connect / connection_lost / pool_acquire operation) feed the breaker — a bad
  query or domain error does not trip a healthy tenant. The failure counter is a
  sliding window, so sporadic blips never accumulate into a false trip.
- **Swoole-safe.** The resolved tenant `DatabasePort` is bound into the
  per-request `ModuleContainer` and discarded on `reset()`; the tenant id rides
  on the immutable `Request`/`Identity`, never a static or `CoreContainer`.

## Config (`module.json`)

| Env | Default | Meaning |
|---|---|---|
| `TENANCY_MODE` | `tenant` | `legacy` \| `dual-write` \| `tenant` (migration phases) |
| `TENANCY_REGISTRY_TTL` | `60` | registry cache TTL (s) |
| `TENANCY_BREAKER_THRESHOLD` | `5` | connectivity failures before the breaker opens |
| `TENANCY_BREAKER_WINDOW` | `60` | sliding window (s) failures must occur within |
| `TENANCY_BREAKER_COOLDOWN` | `30` | breaker open window (s) |
| `TENANCY_TEMPLATE_PATH` | bundled | tenant template migrations path |

## Swoole connection pooling (optional optimization)

By default `ConnectionManager` is request-scoped, so tenant sockets aren't reused
across requests. For long-lived OpenSwoole workers, bind `ConnectionManager` (and
the resolver) into the **CoreContainer** in bootstrap so resolved tenant adapters
persist per worker. Cap with an LRU eviction of idle tenant connections so a
worker serving thousands of tenants never holds thousands of open sockets — and
front the DB tier with ProxySQL/PgBouncer under PHP-FPM.
