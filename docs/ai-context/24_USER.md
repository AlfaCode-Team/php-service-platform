# User Plugin — Central Identity

> AI reference for `Plugins\User\` (solves `user.management`).
> The GLOBAL central identity store: CRUD, credential verification, email
> verification, transactional outbox. Pairs with [09_SECURITY](09_SECURITY.md)
> (Auth issues tokens over this identity), [23_TENANCY](23_TENANCY.md) (memberships
> link users to tenants), [08_EVENTS](08_EVENTS.md).

---

## WHAT IT DOES

Owns the **global, central `users` table** — identity is centralized, username
and email are globally unique. Repositories + the outbox are pinned to the
**central** connection (the `ConnectionManager` default), so identity I/O is
NEVER redirected to a tenant DB even when `TenantContextStage` rebinds
`DatabasePort` for the request.

`requires: ["database.management", "crypto.services", "cache.redis", "view.rendering"]`
`exposes: ["Plugins\User\API\Contracts\UserServiceContract"]`

---

## PUBLISHED CONTRACT — `UserServiceContract`

`Application/Services/UserService.php`. All methods take/return DTOs (`API/DTOs/`)
— never entities or raw arrays across the boundary.

| Method | Notes |
|---|---|
| `register(RegisterUserDTO): UserDTO` | tx + outbox; emits `user.registered` |
| `list(ListUsersQuery): UserPage` | paginated |
| `find(id): ?UserDTO` | |
| `update(id, UpdateUserDTO): ?UserDTO` | optimistic-locked (`version`); emits `user.updated` |
| `verifyEmail(id, VerifyEmailDTO): ?UserDTO` | |
| `verifyCredentials(identifier, password): ?UserDTO` | timing-safe, rate-limited; rehash-on-login |
| `delete(id): bool` | emits `user.deleted` |

`RegisterUserDTO::fromRequest()` also reads the request **`tenant`** attribute
(set by Tenancy's `TenantContextStage`) into `$tenantId` — an opaque string that
is forwarded on the `user.registered` event so Tenancy can assign membership.
User stays tenant-agnostic (no Tenancy import).

---

## SERVICE PATTERN (mandatory shape)

Mutating methods follow the kernel transaction+event pattern (see [04_SERVICE](04_SERVICE.md)):

```
collector->beginCollection(); transaction->begin();
  try { entity op → flushEvents() → repository.insert() → commit(); }
  catch { rollback(); collector->discard(); throw wrap(...); }
collector->release();           // domain events
audit->record('user.…', [...]); // security audit (also persisted to audit_log)
```

Integration events are written to the **transactional outbox** inside the tx
(durable), NOT dispatched inline.

---

## EVENTS — TRANSACTIONAL OUTBOX

`emits: ["user.registered", "user.updated", "user.deleted"]`

- `flushEvents()` → `toIntegration()` builds the integration event and
  `OutboxWriter::write()`s it into `user_outbox` **in the same transaction** as
  the user change (atomic, no lost/phantom events).
- `user:outbox:relay` (CLI command, `Infrastructure/Cli/RelayUserOutboxCommand`)
  drains pending rows and dispatches a `GenericIntegrationEvent` (carrying the
  stored payload array) to the EventBus. Delivery is **at-least-once** → listeners
  must be idempotent.
- `UserRegisteredIntegrationEvent` carries `userId, username, email, occurredAt`
  **+ `tenantId`** (origin tenant for self-signup; `''` when none). This is how
  Tenancy auto-assigns membership — see [23_TENANCY](23_TENANCY.md).

---

## SECURITY AUDIT — `AuditLogger`

`Infrastructure/Audit/AuditLogger.php`. Records security-relevant actions
(register, update, email verified, login failed/locked-out, password rehash,
delete) — **identifiers + outcomes only, never passwords/hashes/PII**.

- Writes a structured JSON line (via `error_log`, tagged `source=user_audit`).
- **Also persists to the shared central `audit_log` table** when a `DatabasePort`
  is injected: `userId`→`user_id`, `ip`→`ip`, the rest→JSON `meta`, `event_id`
  via `Ulid::generate()`. **Best-effort** (try/catch — an audit write must never
  break the audited action; the log line is the durable fallback).
- `tenant_id` is stamped from the `'tenant.current'` container key published by
  Tenancy's `TenantContextStage` (`has()`-guarded — no Tenancy dependency); `NULL`
  for unscoped/CLI requests.
- Reads/queries of `audit_log` are Tenancy's `AuditReader`/`AuditLogRepository`.

---

## DATA

| Table | Repository | Notes |
|---|---|---|
| `users` | `UserRepository` (central) | ULID `user_id`; unique username/email; `password_hash`, `remember_token` (60/64 char); `version` (optimistic lock) |
| `user_outbox` | `OutboxWriter` / `OutboxRelay` (central) | transactional integration-event outbox |

- Passwords hashed via `crypto.services` (bcrypt, rehash-on-login). Hashes and
  remember tokens NEVER cross the API boundary.
- `UserId`/`Ulid` value objects generate the 26-char public id.
- See [05_REPOSITORY](05_REPOSITORY.md), [18_MIGRATIONS](18_MIGRATIONS.md).

---

## ROUTES (`module.json`)

- HTML (View): `GET /users`, `/users/create`, `/users/{id}`, `/users/{id}/edit`.
- JSON (`/ajx/...`): `POST /ajx/users` register (`throttle:10,1` — **anonymous**,
  not auth-gated), `GET/PUT/PATCH/DELETE /ajx/users/{id}` + verify-email (`auth`).

---

## ABSOLUTE RULES

```
✓ users + user_outbox are CENTRAL — pin repositories to the ConnectionManager default, never the request DatabasePort.
✓ Integration events go through the transactional outbox; relayed at-least-once → idempotent listeners.
✓ Audit records identifiers/outcomes ONLY; DB persistence is best-effort and never aborts the action.
✓ Password hashes / remember tokens never appear in a DTO or response.
✓ Writes are optimistic-locked on `version`.
✗ Importing a Tenancy class from User — User forwards the opaque 'tenant' request attribute only.
✗ Dispatching integration events inline instead of via the outbox.
✗ Returning entities/arrays across the contract — use API/DTOs.
✗ Reading user identity from a tenant-routed DatabasePort — always central.
```
