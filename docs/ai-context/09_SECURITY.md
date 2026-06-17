# AlfacodeTeam PhpServicePlatform — Security Layer Context

> The SecurityGateway runs **before any module loads**. A denied request never touches
> module code, costs microseconds, and returns immediately.

---

## Security Architecture

```
Request arrives
    │
    ▼
SecurityGateway (always resident — never reloaded)
    │
    ├── FirewallLayer.check()         ← IP blocklist/allowlist (cheapest)
    │         DENY → 403 immediately ──────────────────────────────────────►
    │
    ├── RateLimiterLayer.check()      ← sliding window counter (Redis)
    │         DENY → 429 immediately ──────────────────────────────────────►
    │
    └── TokenValidatorLayer.check()   ← JWT/API key verify (most expensive)
              DENY → 401 immediately ──────────────────────────────────────►
              │
              CLEARED → Identity attached to Request
                              │
                              ▼
                       after.security hooks  ← module-registered stages
                              │
                              ▼
                       Rest of pipeline
```

**Critical:** The order is fixed. Cheapest checks run first. TokenValidator last.
Never reverse the order — it costs unnecessary compute.

---

## SecurityLayerContract

```php
interface SecurityLayerContract
{
    /**
     * Check the request. Return allow or deny.
     * On allow: optionally attach or augment Identity on the request.
     * On deny:  return immediately — no further layers run.
     */
    public function check(Request $request): SecurityVerdict;
}
```

---

## SecurityVerdict

```php
final class SecurityVerdict
{
    // Allow — request proceeds to the next security layer or pipeline
    public static function allow(Request $request): self;

    // Deny — pipeline stops here, HTTP error returned immediately
    public static function deny(int $statusCode, string $reason): self;

    public function isDenied(): bool;
    public function isAllowed(): bool;
    public function identity(): ?Identity;
    public function statusCode(): int;    // 401 | 403 | 429
    public function reason(): string;
}
```

---

## Identity — The Security Passport

```php
final readonly class Identity
{
    public function __construct(
        public readonly string $userId,
        public readonly string $tenantId,
        public readonly array  $roles,        // ['admin', 'user']
        public readonly array  $permissions,  // ['invoice:create', 'invoice:view-all']
        public readonly string $tokenType,    // 'jwt' | 'api_key' | 'session'
    ) {}

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $perm): bool
    {
        return in_array($perm, $this->permissions, true);
    }

    public function isGuest(): bool
    {
        return empty($this->userId);
    }
}
```

**Identity is set once by the SecurityGateway and never modified downstream.**
All service-level authorization uses `$this->identity->hasPermission()` or `->hasRole()`.

---

## Built-in Security Layers

### FirewallLayer
```php
// Checks IP against blocklist (bloom filter or file) and allowlist
// module.json for the security.firewall module:
// "config": ["SECURITY_IP_BLOCKLIST_PATH", "SECURITY_IP_ALLOWLIST"]
```

### RateLimiterLayer
```php
// Sliding window counter using CachePort (Redis)
// Per-IP, per-user, per-route limits configurable in config/security.php
// Returns: X-RateLimit-Limit, X-RateLimit-Remaining, Retry-After headers
```

### TokenValidatorLayer
```php
// Validates JWT or API key. Builds Identity from claims.
// Routes in config("security.public_routes") bypass this layer.
// HMAC signature verification — timing-safe hash_equals()
```

---

## Writing a Custom Security Layer

```php
final class RequireVerifiedEmailLayer implements SecurityLayerContract
{
    public function __construct(
        private readonly CachePort $cache,
    ) {}

    public function check(Request $request): SecurityVerdict
    {
        $identity = $request->identity();

        // If no identity yet (guest or public route), allow through
        if (!$identity || $identity->isGuest()) {
            return SecurityVerdict::allow($request);
        }

        // Check email verification from cache (fast path)
        $verified = $this->cache->get("email_verified:{$identity->userId}");

        if ($verified === null) {
            // Cache miss — check is done at service layer for first request
            return SecurityVerdict::allow($request);
        }

        if (!$verified) {
            return SecurityVerdict::deny(403, 'Email address is not verified');
        }

        return SecurityVerdict::allow($request);
    }
}
```

Register it in `bootstrap/http.php`:
```php
$kernel->withSecurity([
    new FirewallLayer(...),
    new RateLimiterLayer(...),
    new TokenValidatorLayer(...),
    new RequireVerifiedEmailLayer($cache), // ← custom layer last
]);
```

---

## JWT Token Lifecycle

```
Login
  │
  ▼
AuthService.login(LoginDTO)
  ├── Verify password (bcrypt.verify — constant time)
  ├── Issue access token  (JWT, 1 hour TTL, signed with HS256)
  ├── Issue refresh token (JWT, 7 days TTL, stored in Redis)
  └── Return {access_token, refresh_token, expires_in}

Subsequent requests
  │
  ▼
TokenValidatorLayer.check()
  ├── Extract Bearer token from Authorization header
  ├── Decode header + payload (base64url)
  ├── Verify signature with hash_equals() — TIMING SAFE
  ├── Check exp claim
  └── Build Identity from claims → attach to Request

Refresh
  │
  ▼
AuthService.refresh(RefreshTokenDTO)
  ├── Look up refresh token in Redis
  ├── Check if already rotated (reuse detection)
  ├── Mark current token as rotated
  ├── Issue new token pair
  └── Return new {access_token, refresh_token}
```

---

## Rate Limit Configuration

```php
// config/security.php
return [
    'limits' => [
        'global_ip'  => ['max' => 1000, 'window' => 60, 'strategy' => 'sliding_window'],
        'per_user'   => ['max' => 300,  'window' => 60, 'strategy' => 'sliding_window'],
        'routes'     => [
            'POST /api/auth/login'  => ['max' => 5,  'window' => 60],
            'POST /api/auth/forgot' => ['max' => 3,  'window' => 3600],
            'POST /api/payments'    => ['max' => 30, 'window' => 60],
        ],
    ],
    'public_routes' => [
        'POST /api/auth/login',
        'POST /api/auth/register',
        'GET  /api/health',
    ],
];
```

---

## Service-Level Authorization Pattern

```php
// After SecurityGateway clears the request, authorization happens in the Service layer.
// SecurityGateway: WHO is this? (authentication)
// Service layer:   WHAT can they do? (authorization)

public function delete(string $invoiceId): void
{
    $invoice = $this->repository->find($invoiceId);

    // RBAC: does the user have the permission at all?
    if (!$this->identity->hasPermission('invoice:delete')) {
        throw new ServiceException('invoice.delete.unauthorized');
    }

    // ABAC: does the user own this specific resource?
    if ($invoice->clientId()->value() !== $this->identity->userId
        && !$this->identity->hasPermission('invoice:delete-any')) {
        throw new ServiceException('invoice.delete.unauthorized');
    }

    $this->repository->softDelete($invoiceId);
}
```

---

## AI Instructions for Security Code

When generating or reviewing security code:

- **DO** place all SecurityLayer implementations in the Project layer or a dedicated security module
- **DO** use `hash_equals()` for all token and signature comparisons — never `===` or `==`
- **DO** keep FirewallLayer first, RateLimiterLayer second, TokenValidatorLayer last
- **DO** attach `Identity` to the request in `TokenValidatorLayer`, not in other layers
- **DO** check authorization in the Service layer, not in the SecurityGateway layers
- **DON'T** access `DatabasePort` from a SecurityLayer — use `CachePort` only
- **DON'T** put authorization (what they can do) in the SecurityGateway — that's authentication (who they are)
- **DON'T** throw exceptions from SecurityLayer — return `SecurityVerdict::deny()`
- **DON'T** use `===` for timing-sensitive comparisons (HMAC, token comparison)
- **DON'T** log passwords, tokens, or secrets anywhere in the security pipeline
