# How to Use These Files in Claude.ai — Complete Guide

---

## OPTION A — Claude Projects (RECOMMENDED — permanent context)

This is the best method. You register the files once and every conversation in the project automatically has full AlfacodeTeam PhpServicePlatform knowledge. No pasting required.

### Step-by-Step Setup

**Step 1 — Create a Project**
1. Open claude.ai
2. Click **"Projects"** in the left sidebar
3. Click **"Create project"**
4. Name it: `AlfacodeTeam PhpServicePlatform Framework` (or your app name)
5. Click **Create**

**Step 2 — Add the Context Files as Project Knowledge**
1. Inside your project, click **"Project knowledge"** (or the document icon)
2. Click **"Add content"**
3. Upload or paste these files **in this order**:

```
UPLOAD THESE — ALWAYS:
1. 00_AI_SYSTEM_PROMPT.md      ← The rules. Most important file.
2. 00_SENTINEL_OVERVIEW.md     ← Full architecture + request lifecycle
3. 13_ANTIPATTERNS.md          ← What NOT to do. Prevents wrong suggestions.

UPLOAD THESE — FOR YOUR MAIN WORK AREA:
4. 03_DOMAIN.md                ← if you write domain code often
5. 04_SERVICE.md               ← if you write service code often
6. 05_REPOSITORY.md            ← if you write repository code often
7. 08_EVENTS.md                ← if you work with events often
```

> **Note:** Claude Projects has a knowledge limit. If you hit it, prioritise the first 3 files
> and add layer files only for what you work on daily.

**Step 3 — Add Your Own Code as Knowledge (optional but powerful)**
1. In the same "Project knowledge" area, paste your actual module code
2. For example, paste your `InvoiceModule/` code so Claude sees your real codebase
3. Claude will use your code as reference when generating new code

**Step 4 — Start a Conversation**
1. Click **"New conversation"** inside the project
2. The project knowledge is automatically applied — no pasting needed
3. Just ask your question directly

**Example first message in the project:**
```
I need to create a new module called "subscription" that handles recurring billing.
The module solves "subscription.management" and requires the invoice.generation and
payment.processing domains. Please scaffold the module structure starting with
module.json and the Domain layer.
```

---

## OPTION B — Single Conversation (paste context manually)

Use this when you are not using a Project, or for a one-off task.

### What to Paste and Where

**Step 1 — Open a new Claude conversation**

**Step 2 — Paste the system prompt FIRST (before anything else)**

Copy the entire contents of `00_AI_SYSTEM_PROMPT.md` and paste it.
Do NOT ask a question yet. Just paste the file and send it.

Expected Claude response: Claude acknowledges the AlfacodeTeam PhpServicePlatform framework rules.

**Step 3 — Paste the layer-specific file for your task**

| What you are doing | Paste this file next |
|---|---|
| Creating a new module | `02_MODULE.md` |
| Writing domain entities / value objects | `03_DOMAIN.md` |
| Writing a service | `04_SERVICE.md` |
| Writing a repository | `05_REPOSITORY.md` |
| Writing a gateway | `06_GATEWAY.md` |
| Writing a controller | `07_CONTROLLER.md` |
| Working with events | `08_EVENTS.md` |
| Security / authentication | `09_SECURITY.md` |
| CSRF protection (tokens, forms) | `21_CSRF.md` |
| Writing tests | `10_TESTING.md` |
| Writing background jobs | `12_WORKER.md` |
| Reviewing code / debugging | `13_ANTIPATTERNS.md` |

**Step 4 — Ask your question**

Now ask what you need. Examples below.

---

## WHAT TO SAY TO CLAUDE — Prompt Templates

Copy these exactly. They are optimised for AlfacodeTeam PhpServicePlatform.

---

### TEMPLATE 1 — Generate a complete new module

```
I need a new AlfacodeTeam PhpServicePlatform module with these specifications:

Module name: [name]
Solves domain: [e.g. subscription.management]
Requires: [e.g. database.query, invoice.generation]
Exposes: [e.g. SubscriptionServiceContract]

Routes needed:
- GET  /api/subscriptions
- POST /api/subscriptions
- GET  /api/subscriptions/{id}
- DELETE /api/subscriptions/{id}

Domain entity fields:
- id (ULID)
- clientId
- planId
- status (active, cancelled, paused)
- currentPeriodStart
- currentPeriodEnd
- cancelledAt (nullable)

Config vars needed: SUBSCRIPTION_TRIAL_DAYS

Integration events to emit: subscription.created, subscription.cancelled
Integration events to listen to: payment.failed

Please generate in this order:
1. module.json
2. Domain/ValueObjects/ (all value objects)
3. Domain/Entities/Subscription.php (full entity with state machine)
4. API/Contracts/SubscriptionServiceContract.php
5. Application/Services/SubscriptionService.php (with full transaction pattern)
6. Infrastructure/Persistence/SubscriptionRepository.php
7. Infrastructure/Http/Controllers/SubscriptionController.php
8. Provider.php
9. tests/Unit/Domain/SubscriptionTest.php
```

---

### TEMPLATE 2 — Review existing code against AlfacodeTeam PhpServicePlatform rules

```
Please review this [layer name] code for AlfacodeTeam PhpServicePlatform compliance.
Check specifically:
- Does it violate any of the Five Access Rules?
- Is the transaction + event pattern correct?
- Are exceptions thrown from the right layer?
- Is there any business logic in the wrong layer?
- Are there any anti-patterns from the AlfacodeTeam PhpServicePlatform guidelines?

Here is the code:

[paste your code here]
```

---

### TEMPLATE 3 — Generate a domain entity

```
Generate a AlfacodeTeam PhpServicePlatform domain entity for [entity name] with these requirements:

Fields:
- [field: type — e.g. id: InvoiceId, amount: Money, status: enum]

State transitions:
- DRAFT → ISSUED (trigger: issue())
- ISSUED → PAID (trigger: pay())
- ISSUED → CANCELLED (trigger: cancel())

Business rules:
- [rule 1 — e.g. Cannot issue without at least one line item]
- [rule 2 — e.g. Cannot cancel a paid entity]

Domain events to record:
- [EntityName]Created (on creation)
- [EntityName]Issued (on issue)
- [EntityName]Paid (on pay)

Follow the AlfacodeTeam PhpServicePlatform domain entity pattern exactly:
- final class, private constructor, public static factory method create()
- public static factory method reconstitute() for hydration (no events)
- releaseEvents() clears and returns the event buffer
- All state transitions enforce their preconditions with ensureStatus()
- Value objects for all fields that have validation rules
```

---

### TEMPLATE 4 — Generate a service with full transaction pattern

```
Generate a AlfacodeTeam PhpServicePlatform Application Service for [Name]Service implementing [Name]ServiceContract.

Method to implement: [methodName]([DTO] $dto): [ReturnType]

Steps this method must perform:
1. [Authorization check — e.g. verify ownership]
2. [Domain operation — e.g. create entity, call method]
3. [Persist via repository]
4. [Dispatch integration event after commit]

Dependencies to inject:
- [Name]Repository (internal — not in contract)
- [Name]GatewayContract (if external API needed)
- TransactionManager
- DomainEventCollector
- IntegrationEventBus
- Identity

Use the EXACT transaction pattern:
collector->beginCollection()
transaction->begin()
try { ... commit } catch { rollback + discard + throw ServiceException }
eventBus->dispatch() AFTER the try/catch
```

---

### TEMPLATE 5 — Generate a repository with complex queries

```
Generate a AlfacodeTeam PhpServicePlatform Repository for [Name]Repository with these methods:

1. find(string $id): [Entity]
   - Include tenant_id scoping
   - Include deleted_at IS NULL
   - Load related [child entities] in a second query
   - Translate \PDOException to RepositoryException
   - Throw RepositoryException if not found

2. save([Entity] $entity): void
   - Use INSERT ... ON DUPLICATE KEY UPDATE
   - Include optimistic locking with version column
   - Translate \PDOException

3. findByCriteria([Name]Criteria $criteria): PaginatedResult
   - Build WHERE from criteria object
   - Sanitize ORDER BY against allowlist
   - Return PaginatedResult with data, total, page, perPage, lastPage

Also generate:
- [Name]Hydrator with hydrate(array $row): [Entity] and dehydrate([Entity]): array
- Migration file: Create[Name]sTable with standard columns
  (id CHAR(26), tenant_id CHAR(26), soft delete, version, timestamps)
- Appropriate indexes for the query patterns
```

---

### TEMPLATE 6 — Generate tests for an existing service

```
Generate a complete test suite for this AlfacodeTeam PhpServicePlatform service:

[paste your service code here]

Generate:
1. Unit test class [Name]ServiceTest using:
   - InMemory[Name]Repository fake
   - FakeTransactionManager
   - FakeIntegrationEventBus
   - DomainEventCollector
   - Identity::asUser('user-1', 'tenant-abc')

2. Test methods (generate all of these):
   - test_happy_path_succeeds_and_commits()
   - test_persists_entity_after_create()
   - test_dispatches_integration_event_after_commit()
   - test_does_not_dispatch_event_on_failure()
   - test_rolls_back_transaction_on_failure()
   - test_discards_domain_events_on_rollback()
   - test_unauthorized_user_throws_service_exception()
   - test_validation_failure_before_transaction_begins()

3. The fakes:
   - InMemory[Name]Repository with failOnNextSave() and assertCount()
   - All other fakes are standard — use the canonical versions
```

---

### TEMPLATE 7 — Debug a AlfacodeTeam PhpServicePlatform error

```
I am getting this error in my AlfacodeTeam PhpServicePlatform application:

[paste the full error message and stack trace]

This is the code involved:

[paste the relevant code]

Please:
1. Identify which AlfacodeTeam PhpServicePlatform rule is being violated (if any)
2. Explain exactly why this error occurs
3. Show the correct fix
4. Explain how to prevent this class of error in the future
```

---

### TEMPLATE 8 — Generate a job (background worker)

```
Generate a AlfacodeTeam PhpServicePlatform background job:

Job name: [Name]Job
Queue: [default/emails/critical/reports]
Retry: max 3, strategy exponential, jitter true
Timeout: [N] seconds
Requires: [list of module solves values]

What the job must do:
1. [step 1]
2. [step 2]

On permanent failure (after max retries):
- [what to do in failed() method]

Also generate:
- module.json for this job
- [Name]Payload readonly class (input DTO)
- Unit test with FakeInvoiceService, FakeMailPort etc.
- Fake that simulates the dependency behaviour
```

---

## TIPS FOR BEST RESULTS WITH CLAUDE

### DO

- **Be specific about which layer you are working in.** Start your message with:
  *"I am writing a Repository layer class for..."*

- **Show Claude what already exists.** Paste your existing module code so Claude matches
  your naming conventions, existing value objects, and real field names.

- **Ask Claude to check its own output.** After generating code, ask:
  *"Now review this against the AlfacodeTeam PhpServicePlatform Five Access Rules and the transaction pattern.
  Is anything wrong?"*

- **Use the anti-pattern template for code review.** It surfaces violations faster than
  asking Claude to generally review code.

- **Break large modules into steps.** Ask for module.json first, then Domain layer,
  then Service layer. Each step builds on the previous.

### DON'T

- **Don't ask Claude to "add this to Laravel"** — Claude will mix Laravel patterns.
  Always specify "this is AlfacodeTeam PhpServicePlatform, not Laravel".

- **Don't ask for "quick solutions"** — AlfacodeTeam PhpServicePlatform has strict rules. Quick solutions usually
  skip the access chain or transaction pattern.

- **Don't paste Claude's output directly without reading it.** Check:
  - Is the event dispatched after commit (not inside try)?
  - Does the repository include `tenant_id`?
  - Are vendor exceptions caught in the gateway?

---

## CONTEXT WINDOW MANAGEMENT

Claude has a context window limit. For long sessions:

- **Start fresh for each module** — paste the system prompt again at the start of each module
- **Keep the system prompt** (`00_AI_SYSTEM_PROMPT.md`) in every conversation — it is the most
  important file
- **Drop layer files you are not using** — if you are writing domain code, you don't need
  `12_WORKER.md` in context
- **Use Claude Projects** — it handles context automatically and is much more convenient

---

## RECOMMENDED WORKFLOW FOR BUILDING A COMPLETE MODULE

**Conversation 1 — Design**
```
Paste: 00_AI_SYSTEM_PROMPT.md + 00_SENTINEL_OVERVIEW.md + 02_MODULE.md
Ask: "Help me design the module.json for a [name] module that does [description]"
```

**Conversation 2 — Domain Layer**
```
Paste: 00_AI_SYSTEM_PROMPT.md + 03_DOMAIN.md
Show: the module.json from conversation 1
Ask: "Generate all domain entities, value objects, and domain events for this module"
```

**Conversation 3 — Service Layer**
```
Paste: 00_AI_SYSTEM_PROMPT.md + 04_SERVICE.md + 08_EVENTS.md
Show: the domain code from conversation 2
Ask: "Generate the service contract and service implementation"
```

**Conversation 4 — Infrastructure Layer**
```
Paste: 00_AI_SYSTEM_PROMPT.md + 05_REPOSITORY.md + 07_CONTROLLER.md
Show: the service contract from conversation 3
Ask: "Generate the repository, hydrator, migration, and controller"
```

**Conversation 5 — Tests**
```
Paste: 00_AI_SYSTEM_PROMPT.md + 10_TESTING.md
Show: the service and repository code
Ask: "Generate the complete test suite"
```

**Conversation 6 — Review**
```
Paste: 00_AI_SYSTEM_PROMPT.md + 13_ANTIPATTERNS.md
Show: all generated code
Ask: "Review the entire module for AlfacodeTeam PhpServicePlatform compliance violations"
```
