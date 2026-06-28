# Auth Plugin — Authentication (tokens + sessions)

> AI reference for `Plugins\Auth\` (solves `auth.identity`).
> Issues credentials (JWT, personal access tokens) and provides the
> SecurityLayer verifiers the kernel runs before any module loads. Pairs with
> [09_SECURITY](09_SECURITY.md), [24_USER](24_USER.md) (verifies credentials),
> [26_OAUTH2](26_OAUTH2.md) (OAuth2 access tokens are the same JWTs this layer
> verifies).

---

## WHAT IT DOES

The kernel ships **no** token validator — Auth fills the intended "AuthModule
layer" slot. It splits cleanly:

- **Issuance** lives in `AuthService` (exposed via `AuthServiceContract`): mint
  JWTs, create/revoke personal access tokens (PATs), establish/tear down web
  sessions, hash/verify passwords.
- **Verification** lives in `SecurityLayer` classes a project wires into
  `Kernel::withSecurity([...])`; the SecurityGateway runs them before any module
  loads (deny = zero module cost).

```
requires: ["database.management", "crypto.services", "user.management"]
exposes:  ["Plugins\Auth\API\Contracts\AuthServiceContract"]
```
Control-plane tables (`personal_access_tokens`) are pinned to the **central**
connection. The session login flow verifies credentials via `UserServiceContract`.

---

## SECURITY LAYERS (wired in the project bootstrap)

### `JwtAuthLayer` — stateless Bearer JWT
```php
new JwtAuthLayer(
    secret:   $hsSecretOrPublicKeyPem,   // HS secret, or PEM PUBLIC key for RS/ES/PS
    algo:     'RS256',                   // single pinned algo — never trust the token's `alg`
    issuer:   env('JWT_ISSUER'),         // when set, `iss` MUST match
    audience: env('JWT_AUDIENCE'),       // when set, `aud` MUST contain it (list-aware, hash_equals)
    leeway:   60,                        // clock-skew tolerance for exp/iat/nbf
    revocations: $cachePort,             // optional jti deny-list
);
```
- No `Authorization` header → **allow as guest** (public routes keep working).
- Valid Bearer → `Identity` from `sub`/`tnt`/`roles`/`permissions`.
- Malformed / expired / wrong iss|aud / **revoked `jti`** → `deny(401)`.
- Revocation deny-list **fails OPEN** on a cache outage (token is otherwise valid).

### `PersonalAccessTokenLayer` — DB-backed `Bearer <id>.<secret>`
Hashes (`sha256`) and matches against `personal_access_tokens`; **enforces
`expires_at`** (expired = absent), loads the token's `abilities` into
`Identity.permissions`, and stamps `last_used_at`. Empty `tenantId` (unscoped /
central) — consistent with the JWT layer.

JWT/JOSE verification is the ONLY auth the kernel delegates here; everything else
(firewall, rate-limit, CSRF) is kernel-native.

---

## PUBLISHED CONTRACT — `AuthServiceContract`

| Method | Notes |
|---|---|
| `issueJwt(userId, claims, ttl): string` | adds `iat/nbf/exp/jti`, plus `iss/aud` when configured. Asymmetric algos sign with the **private key** (`JWT_PRIVATE_KEY[_FILE]`), optional `kid` |
| `revokeJwt(jti, ttl): void` | deny-lists a `jti` via `CachePort` (key `auth:jwt:revoked:<jti>`) so a token dies before expiry |
| `createPersonalAccessToken(userId, name, abilities, ttl): {id, token}` | plaintext returned ONCE; only the hash is stored; optional abilities + expiry |
| `revokePersonalAccessToken(id): void` | |
| `startSession(SessionPort, userId, roles, permissions, tenantId): void` | rotates session id (fixation defence), stores identity |
| `endSession(SessionPort): void` | invalidate + rotate |
| `hashPassword / verifyPassword` | bcrypt/argon2 via `HashingPort`, timing-safe |

---

## SESSION AUTH (web + AJAX)

The session is opened at `after.load` (`StartSessionStage`, priority 20) — AFTER
the SecurityGateway — so session auth CANNOT be a SecurityLayer. Instead
`SessionAuthStage` is an `after.load` hook at **priority 22** (after session
start, before the route `auth` filter):

- A request already carrying a token-derived `Identity` is left untouched (token
  wins).
- An anonymous request with a logged-in session gets a `tokenType: 'session'`
  Identity rebuilt from the session.
- The same `auth` route filter then protects **both** token and session callers.

Endpoints (`SessionAuthController`): `POST /auth/login` (verifies via User module,
then `startSession`), `POST /auth/logout`, `GET /auth/me`. CSRF is the kernel's
`CsrfTokenLayer` (these routes are outside `/api`).

---

## CLI

- `auth:tokens:prune [--dry] [--watch=SECONDS]` — delete expired PATs (cron or a
  supervised loop for no-cron environments).

---

## CONFIG (env)

`JWT_SECRET`, `JWT_ALGO` (default HS256), `JWT_ISSUER`, `JWT_AUDIENCE`,
`JWT_PRIVATE_KEY` / `JWT_PRIVATE_KEY_FILE` (asymmetric signing — file form keeps
keys off the process env), `JWT_KID`, `AUTH_PAT_TABLE`.

---

## RULES

```
✓ Verification = SecurityLayers (gateway); issuance = AuthService. Never mix.
✓ Pin a SINGLE algo in JwtAuthLayer — never let the token's `alg` choose the verifier.
✓ Asymmetric (RS/ES/PS) for any deployment where verifiers must not hold the signing secret.
✓ PATs: store only the hash, return plaintext once, enforce expires_at, load abilities as permissions.
✓ Session login AFTER credential verification; rotate the session id (fixation defence).
✗ A SecurityLayer that THROWS — always return a SecurityVerdict.
✗ Trusting a `tnt` claim as authorization — it is a routing hint; authz keys on (userId, tenantId, role/permission).
✗ getenv() for JWT_* — use env() (see 11_PROJECT).
```
