# AlfacodeTeam PhpServicePlatform Framework — Master AI System Prompt

> **Paste this entire file as a system prompt when working on AlfacodeTeam PhpServicePlatform code.**
> It gives any AI assistant the complete context needed to produce correct, idiomatic output.

---

## FRAMEWORK IDENTITY

You are helping develop a PHP 8.2+ application built on the **AlfacodeTeam PhpServicePlatform Framework** using the
**Gated Demand Architecture (GDA)** pattern. This is NOT Laravel, Symfony, Slim, or any other
conventional PHP framework. Rules that apply to those frameworks DO NOT apply here.

---

## THE FIVE ACCESS RULES — NEVER VIOLATE

```
Controller  →  Service (via published contract interface ONLY)
Service     →  Repository  AND  Gateway
Repository  →  DatabasePort ONLY
Gateway     →  Vendor SDK ONLY
Domain      →  NOTHING EXTERNAL
```

If code violates these rules, the runtime throws `ScopeViolationException`. These are not
conventions — they are enforced.

---

## LAYER DEFINITIONS

### Domain (`Domain/`)
- Pure PHP. Zero imports from outside `Domain/`.
- Entities: `final class`, private constructor, public static factory methods.
- Value Objects: `final readonly class`, self-validating, immutable.
- Domain Events: `final readonly class`, past tense name, no external deps.
- Status Enums: backed string enums with state machine helpers.

### Service (`Application/Services/`)
- ONLY layer calling both Repository and Gateway.
- Owns transaction boundaries: `begin → save → commit / rollback`.
- Collects domain events during transaction via `DomainEventCollector`.
- Dispatches integration events AFTER commit — NEVER inside `try` block.
- Calls `collector->discard()` in EVERY catch block.
- Receives `Identity` via constructor injection.

### Repository (`Infrastructure/Persistence/`)
- ONLY layer using `DatabasePort`.
- Returns domain objects, never raw arrays.
- Translates `\PDOException` to `RepositoryException`.
- ALWAYS includes `tenant_id` in WHERE clauses.
- ALWAYS includes `deleted_at IS NULL` (soft delete by default).
- Contains SQL but zero business logic.

### Gateway (`Infrastructure/Gateways/`)
- ONLY layer using vendor SDKs.
- Catches ALL vendor exceptions, translates to `GatewayException`.
- Returns domain-friendly result types (not raw vendor objects).
- Zero business logic.

### Controller (`Infrastructure/Http/Controllers/`)
- Three lines: `$dto = DTO::fromRequest($request)` → `$service->method($dto)` → `Response::json()`.
- Calls Service via published contract interface only.
- No authorization logic — that belongs in the Service.
- No business logic — ever.

---

## MODULE STRUCTURE

Every module has `module.json` as the **single source of truth**:

```json
{
  "name":     "invoice",
  "version":  "1.0.0",
  "solves":   "invoice.generation",
  "type":     "module",
  "requires": ["database.query"],
  "exposes":  ["InvoiceServiceContract"],
  "routes":   [{ "method": "POST", "path": "/api/invoices", "handler": "InvoiceController@create" }],
  "emits":    ["invoice.created"],
  "listens":  ["payment.succeeded"],
  "config":   ["INVOICE_CURRENCY"]
}
```

Routes are ONLY in `module.json`. Config vars MUST be declared in `config[]` or boot fails.

---

## TRANSACTION + EVENT PATTERN (MANDATORY SHAPE)

```php
$this->collector->beginCollection();
$this->transaction->begin();
try {
    // domain work + repository save
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();   // ALWAYS — no phantom events
    throw new ServiceException('module.action.failed', previous: $e);
}
// Integration event dispatch ONLY after commit
$this->eventBus->dispatch(new SomethingHappenedIntegrationEvent(...));
```

---

## EVENT TYPES

| | Domain Event | Integration Event |
|---|---|---|
| Location | `Domain/Events/` | `API/IntegrationEvents/` |
| Scope | Internal to module | Cross-module |
| Dispatch | `collector->collect()` during tx | `eventBus->dispatch()` after commit |
| On rollback | Discarded — no phantom events | Never dispatched |
| Constructor params | Domain value objects | Primitive types only (string/int/float) |

---

## EXCEPTION RULES

```
Domain layer     → throw \DomainException (built-in PHP)
Service layer    → throw ServiceException
Repository layer → throw RepositoryException (translated from \PDOException)
Gateway layer    → throw GatewayException (translated from vendor exceptions)
Security layer   → return SecurityVerdict::deny() — NEVER throw
```

Always include: `layer: 'service.invoice'`, `context: [...]`, `previous: $e`.

---

## THINGS NEVER TO DO

1. Import another module's `Repository` — use its published `Contract` from `API/Contracts/`
2. Dispatch an integration event inside a `try` block — dispatch after commit
3. Put business logic in a Controller — controllers are 3-line HTTP translators
4. Use `float` for money — use `Money::of($amount, 'USD')` with integer cents
5. Use `static` properties in services — they leak between PHP-FPM requests
6. Put SQL in services — SQL belongs in repositories only
7. Define routes in PHP — routes belong in `module.json` only
8. Declare config in PHP without adding to `module.json config[]` — boot fails
9. Let `\PDOException` escape the repository — translate to `RepositoryException`
10. Let any vendor exception escape the gateway — translate to `GatewayException`
11. Use `===` for token comparison — use `hash_equals()`
12. Return `null` from `find()` — throw `RepositoryException` on not found
13. Catch exceptions in controllers — let `ErrorStage` handle them
14. Omit `declare(strict_types=1)` from any PHP file

---

## TESTING PATTERNS

```php
// Service test — always use fakes, never real infrastructure
$repo      = new InMemoryInvoiceRepository();
$txn       = new FakeTransactionManager();
$bus       = new FakeIntegrationEventBus();
$collector = new DomainEventCollector();
$identity  = Identity::asUser('user-1', 'tenant-abc');

$sut = new InvoiceService($repo, $txn, $collector, $bus, $identity);

// After a create call, assert:
$bus->assertDispatched(InvoiceCreatedIntegrationEvent::class, times: 1);
$this->assertTrue($txn->wasCommitted());
```

---

## WHEN ASKED TO GENERATE CODE — CHECKLIST

Before outputting any code, verify:
- [ ] Does it violate any of the Five Access Rules?
- [ ] Is `declare(strict_types=1)` at the top?
- [ ] Does the Service dispatch events after commit (not inside try)?
- [ ] Does the Service call `collector->discard()` in the catch block?
- [ ] Are domain objects `final` and Value Objects `final readonly`?
- [ ] Is money stored as integer cents (not float)?
- [ ] Are routes in `module.json`, not in PHP?
- [ ] Are all config vars declared in `module.json config[]`?
- [ ] Are vendor exceptions caught in the Gateway and translated?
- [ ] Does the Repository always include `tenant_id` and `deleted_at IS NULL`?
