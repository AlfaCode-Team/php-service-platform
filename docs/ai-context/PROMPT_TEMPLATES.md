# AlfacodeTeam PhpServicePlatform — Copy-Paste Prompt Templates

> These are ready-to-use prompts for Claude. Copy the template, fill in the [brackets],
> and paste after the system prompt (`00_AI_SYSTEM_PROMPT.md`).

---

## BEFORE EVERY SESSION — Paste This First

```
I am building a PHP 8.2+ application using the AlfacodeTeam PhpServicePlatform Framework (Gated Demand Architecture).
This is NOT Laravel or Symfony. The Five Access Rules are absolute:
  Controller → Service (contract only)
  Service → Repository AND Gateway
  Repository → DatabasePort only
  Gateway → Vendor SDK only
  Domain → Nothing external

I have shared the AlfacodeTeam PhpServicePlatform context files with you. Always follow them strictly.
If I ask for something that would violate AlfacodeTeam PhpServicePlatform rules, tell me before generating code.
```

---

## PROMPT — Start a brand new module

```
Create a complete AlfacodeTeam PhpServicePlatform module called "[module-name]".

Specifications:
  solves:   "[domain.string]"
  requires: [list the domains it depends on, e.g. "database.query"]
  exposes:  [list the contracts it publishes, e.g. "SubscriptionServiceContract"]

HTTP routes needed:
  [METHOD] [path] → [HandlerClass@method]

Domain entity: [EntityName]
  Fields:
    - [fieldName]: [type, e.g. string / Money / DateTimeImmutable / nullable string]
  
  Status enum values: [DRAFT, ACTIVE, CANCELLED, etc.]
  
  State transitions:
    [STATUS] → [STATUS] via [methodName]() when [condition]
  
  Business rules:
    - [Cannot do X without Y]
    - [Cannot transition from A to B if condition]

Integration events to emit:   [[module].[noun].[past-tense], ...]
Integration events to listen: [[other-module].[event], ...]

Config vars needed: [VAR_NAME, VAR_NAME2]

Generate in this order and stop after each for my review:
1. module.json
2. Domain (Entity + ValueObjects + DomainEvents + Rules)
3. Service contract + Service implementation
4. Repository + Hydrator + Migration
5. Controller
6. Provider.php
7. Tests
```

---

## PROMPT — Add a method to an existing service

```
Add a new method to this AlfacodeTeam PhpServicePlatform service.

Existing service code:
[paste your service code]

New method: [methodName]([ParameterType] $dto): [ReturnType]

What it must do:
  1. Authorization: [who can call this — e.g. owner or admin only]
  2. [step 2 — e.g. validate something]
  3. [step 3 — e.g. update the entity]
  4. [step 4 — e.g. call a gateway]
  5. [step 5 — e.g. persist]
  6. Integration event to dispatch after commit: [EventName]

Also generate:
  - The input DTO class
  - The integration event class
  - Test cases for this method
```

---

## PROMPT — Create a Value Object

```
Create a AlfacodeTeam PhpServicePlatform Value Object for [concept name].

Rules to enforce in the constructor:
  - [rule 1, e.g. must be positive]
  - [rule 2, e.g. must be a valid ISO 3166-1 alpha-2 country code]
  - [rule 3, e.g. maximum 255 characters]

Named constructors needed:
  - [e.g. of(string $raw): self — for creating from user input]
  - [e.g. fromDatabase(string $raw): self — for restoring from DB (skips some validation)]

Operations needed (all return new instances):
  - [e.g. add(self $other): self]
  - [e.g. equals(self $other): bool]

Accessors:
  - [e.g. value(): string]
  - [e.g. formatted(): string]

Make it: final readonly class — immutable — declare(strict_types=1)
```

---

## PROMPT — Create a Domain Entity state machine

```
Create a domain entity with a full state machine.

Entity: [Name]
Status enum values and what each means:
  [STATUS] = [meaning]
  [STATUS] = [meaning]

Valid transitions (from → to, via method):
  [STATUS] → [STATUS] via [method]()    [optional: condition like "only if X is set"]
  [STATUS] → [STATUS] via [method]()

Terminal states (cannot leave): [STATUS, STATUS]

Domain events to record on each transition:
  [method]() → [Name][PastTense]DomainEvent

Business rules to enforce:
  - [rule checked before each transition]

Generate:
  1. Status enum (backed string) with canTransitionTo() helper
  2. Full entity with private constructor + create() + reconstitute()
  3. All transition methods with ensureStatus() guards
  4. All domain event classes
  5. Unit tests for every valid and invalid transition
```

---

## PROMPT — Write a Repository with specific query patterns

```
Write a AlfacodeTeam PhpServicePlatform Repository for [Name]Repository.

Table name: [table_name]
Has soft delete: yes/no
Has tenant scoping: yes/no
Has optimistic locking (version column): yes/no

Methods needed:

find(string $id): [Entity]
  Joins: [describe any joins to related tables]
  Extra filters: [e.g. must be active, must belong to tenant]

save([Entity] $entity): void
  Strategy: INSERT ... ON DUPLICATE KEY UPDATE

softDelete(string $id): void

findByCriteria([Name]Criteria $criteria): PaginatedResult
  Filterable by: [field, field, field]
  Sortable by: [field, field] (sanitize against allowlist)
  Default sort: [field DESC]

Aggregate queries:
  totalRevenue(string $tenantId, DateRange $range): Money
  countByStatus(string $tenantId): array

Also generate:
  - [Name]Hydrator (hydrate + dehydrate)
  - Migration (Create[Name]sTable) with all indexes
  - InMemory[Name]Repository fake for tests
```

---

## PROMPT — Write a Gateway for a third-party API

```
Write a AlfacodeTeam PhpServicePlatform Gateway for [VendorName][Domain]Gateway.

Vendor SDK class: [\Vendor\ClassName]
Contract to implement: [Name]GatewayContract

Methods needed:
  [methodName]([InputDTO] $dto): [ResultType]
    Vendor SDK call: [describe what it calls]
    Possible vendor exceptions to catch:
      [\VendorException1] → translate to GatewayException with context: [what context]
      [\VendorException2] → translate to GatewayException with context: [what context]

Result type [Name]Result:
  static success(...)
  static failed(string $reason)
  Accessors: [list]

Also generate:
  - [Name]GatewayContract interface
  - [ResultType] value object
  - Fake[Name]Gateway for tests with failOnNextCall() and assertCalledWith()
  - Unit tests for the happy path and each failure scenario
```

---

## PROMPT — Review code for AlfacodeTeam PhpServicePlatform violations

```
Review this code strictly against AlfacodeTeam PhpServicePlatform Framework rules.

Check for ALL of these violations:
  1. Five Access Rules — Controller→Service→Repository→Port, never skipping or reversing
  2. Transaction pattern — is collector->discard() in EVERY catch block?
  3. Event dispatch — are integration events dispatched AFTER commit (not inside try)?
  4. Domain purity — any imports from outside Domain/ namespace?
  5. Repository scope — does it use DatabasePort only? Any business logic?
  6. Gateway scope — does it catch ALL vendor exceptions? Any database calls?
  7. Controller thinness — is there any logic beyond DTO→service→Response?
  8. Money handling — are floats used for money anywhere?
  9. Static state — any static properties that would leak between FPM requests?
  10. Type safety — is declare(strict_types=1) present?
  11. Config declarations — are all env vars declared in module.json config[]?
  12. Cross-module access — is any internal class from another module imported?

Code to review:
[paste code here]

Format your response as:
VIOLATION FOUND: [rule violated]
  File: [filename]
  Line: [line]
  Problem: [what is wrong]
  Fix: [exactly what to change]

NO VIOLATIONS FOUND (if clean)
```

---

## PROMPT — Generate complete test suite

```
Generate a complete test suite for this AlfacodeTeam PhpServicePlatform code.

Code to test:
[paste your code]

Generate:
  1. Unit tests (tests/Unit/) — no real infrastructure, use fakes
     - Every public method that has business logic
     - Happy path
     - Each business rule violation (expect DomainException or ServiceException)
     - Each authorization scenario
     - Transaction rollback path (repository fails → events discarded)

  2. Fakes needed:
     - InMemory[Name]Repository (with find, save, failOnNextSave, assertCount)
     - FakeIntegrationEventBus (with dispatched(), assertDispatched(), assertNotDispatched())
     - FakeTransactionManager (with wasCommitted(), wasRolledBack(), wrap())

  3. For each test:
     - Arrange (setup the fakes and the SUT)
     - Act (call the method)
     - Assert (verify outcome, DB state, events dispatched, transaction committed)

Use PHPUnit 10+. All test classes must have declare(strict_types=1).
```

---

## PROMPT — Debug an exception

```
I am getting this AlfacodeTeam PhpServicePlatform exception and I don't know why:

Exception: [paste full exception message]
Stack trace: [paste first 10 lines of trace]

The code throwing it:
[paste the relevant code]

Tell me:
  1. Which AlfacodeTeam PhpServicePlatform rule is being violated
  2. Why this specific code triggers the violation
  3. The correct fix (show the before/after code)
  4. What pattern to follow to avoid this in future
```

---

## PROMPT — Generate module.json for an existing module

```
I have a module that was built without a proper module.json.
Here is the module's code:

Provider.php:
[paste]

Service:
[paste]

Repository:
[paste]

Controller:
[paste]

Generate the correct module.json by:
  1. Inferring the name from the namespace
  2. Inferring solves from Provider->solves()
  3. Finding all requires[] from what is injected
  4. Finding all exposes[] from what is bound publicly
  5. Finding all routes from the router annotations or comments
  6. Finding all config vars from env() calls
  7. Finding all emits from eventBus->dispatch() calls
  8. Finding all listens from $events->subscribe() calls

Then list any env vars I need to add to my .env file.
```

---

## PROMPT — Explain AlfacodeTeam PhpServicePlatform design decision

```
I don't understand why AlfacodeTeam PhpServicePlatform requires [specific rule or pattern].

For example, I would normally [what you would do in Laravel/Symfony].
But AlfacodeTeam PhpServicePlatform says [what AlfacodeTeam PhpServicePlatform says to do instead].

Please explain:
  1. The exact problem that [specific rule] prevents
  2. What goes wrong in real production applications when this rule is broken
  3. A concrete before/after code example showing the difference
  4. Whether there are any legitimate exceptions to this rule
```

---

## PROMPT — Port an existing Laravel/Symfony controller to AlfacodeTeam PhpServicePlatform

```
I have an existing [Laravel/Symfony] controller and I need to port it to AlfacodeTeam PhpServicePlatform.

Existing code:
[paste controller and related code]

Port this to AlfacodeTeam PhpServicePlatform by:
  1. Identifying which layer each piece of logic belongs to in AlfacodeTeam PhpServicePlatform
  2. Creating the correct AlfacodeTeam PhpServicePlatform layer classes
  3. Ensuring the Five Access Rules are followed
  4. Using the correct transaction + event pattern
  5. Replacing Eloquent/Doctrine with plain entity + repository
  6. Replacing framework exceptions with AlfacodeTeam PhpServicePlatform exception hierarchy

Show me:
  - What goes in Domain/
  - What goes in Application/Services/
  - What goes in Infrastructure/Persistence/
  - What goes in Infrastructure/Http/Controllers/
  - The module.json entries needed
```

---

## QUICK REFERENCE — One-Liner Prompts

```
"Generate the module.json for a module called [name] that [does what]"

"Add a cancel() state transition to this entity: [paste entity]"

"Write the repository query for finding all [entities] that are overdue"

"Generate the integration event for when a [entity] is [past-tense-action]"

"Write the fake for [Name]Repository suitable for service-layer unit tests"

"What is the correct exception to throw from a [repository/gateway/service] when [situation]?"

"Show me the correct pattern for dispatching a job from a service after a transaction commits"

"Review this service method: does it follow the AlfacodeTeam PhpServicePlatform transaction pattern? [paste code]"

"Generate the migration for a [Name] table with standard AlfacodeTeam PhpServicePlatform columns"

"Convert this float money calculation to use the AlfacodeTeam PhpServicePlatform Money value object: [paste code]"
```
