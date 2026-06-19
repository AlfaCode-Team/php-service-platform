# AlfacodeTeam PhpServicePlatform AI Context Files — Usage Guide

This folder contains **AI context files** for the AlfacodeTeam PhpServicePlatform Framework.
Register them with any AI tool to get idiomatic, correct AlfacodeTeam PhpServicePlatform code output.

---

## Native Distribution Context

The framework supports OS-native distribution for end users.

- Release workflow: `.github/workflows/release.yml`
- Trigger: push a tag `v*` (example: `v1.0.0`)
- Release artifacts:
  - Linux: `psp-kernel_<version>_amd64.deb`
  - Windows: `psp-kernel-<version>-windows-x86_64.zip`
  - macOS: `psp-kernel-<version>-macos-universal.tar.gz`

Canonical install/distribution guide:

- `packaging/README.md`

---

## Files in This Folder

| File | Purpose | When to Register |
|---|---|---|
| `00_AI_SYSTEM_PROMPT.md` | **Master prompt** — register this in every session | Always |
| `00_SENTINEL_OVERVIEW.md` | Full architecture overview + rules | Always |
| `01_KERNEL.md` | Kernel internals, ports, pipelines | When working on kernel/boot/security |
| `02_MODULE.md` | module.json schema, Provider pattern | When creating/editing modules |
| `03_DOMAIN.md` | Entity, Value Object, Domain Event patterns | When working in `Domain/` |
| `04_SERVICE.md` | Service layer, transactions, events | When working in `Application/Services/` |
| `05_REPOSITORY.md` | Repository pattern, hydration, SQL | When working in `Infrastructure/Persistence/` |
| `06_GATEWAY.md` | Gateway pattern, vendor SDK wrapping | When working in `Infrastructure/Gateways/` |
| `07_CONTROLLER.md` | HTTP controllers, response format | When working in `Infrastructure/Http/` |
| `08_EVENTS.md` | Domain vs Integration events | When working with events |
| `09_SECURITY.md` | SecurityGateway, Identity, JWT | When working on security |
| `10_TESTING.md` | Test patterns, fakes, port doubles | When writing tests |
| `11_PROJECT.md` | Bootstrap wiring, port adapters | When working on project layer |
| `12_WORKER.md` | Worker pipeline, jobs, retry | When working on background jobs |
| `13_ANTIPATTERNS.md` | What NOT to do | Always — prevents wrong suggestions |
| `14_CLI.md` | CLI commands, argument parsing | When working on CLI commands |
| `15_ERROR_HANDLING.md` | Exception types, error codes, format | When handling errors |
| `17_PHP_IO_CLI.md` | php-io-cli components, I/O, Shell | When working with CLI components |
| `18_MIGRATIONS.md` | LetMigrate engine, migrations, seeders | When writing migrations or schema changes |
| `19_DATABASE.md` | Multi-driver Database module, DatabasePort adapter, connections | When configuring databases or writing repositories |
| `20_FIRST_PARTY_PLUGINS.md` | Ported plugins: Authorization, Auth, SocialAuth, SecurityFilters, Crypto, Validation, I18n, Support, Mail, Pageflow, DevTools | When using auth, crypto, validation, i18n, collections, mail, or the SPA bridge |
| `21_CSRF.md` | CsrfTokenLayer: HMAC-signed CSRF tokens (WP-nonce style), framework wiring, mint/verify usage | When wiring CSRF protection or rendering forms/SPA tokens |

---

## How to Register With Different AI Tools

### Claude (claude.ai)
1. Open a new conversation
2. Paste the contents of `00_AI_SYSTEM_PROMPT.md` as your first message
3. Then paste the layer-specific file(s) relevant to your task
4. Ask your question / describe the code you want generated

**For Claude Projects:**
1. Create a project for your AlfacodeTeam PhpServicePlatform codebase
2. Upload all `.md` files as project knowledge
3. Every conversation in the project automatically has full context

### Cursor (cursor.sh)
1. Copy `00_AI_SYSTEM_PROMPT.md` content to `.cursorrules` in your project root
2. Append the most relevant layer files for your main work areas
3. Cursor reads `.cursorrules` automatically for every AI request

```bash
# In your project root:
cat sentinel-ai-context/00_AI_SYSTEM_PROMPT.md > .cursorrules
cat sentinel-ai-context/03_DOMAIN.md >> .cursorrules
cat sentinel-ai-context/04_SERVICE.md >> .cursorrules
cat sentinel-ai-context/13_ANTIPATTERNS.md >> .cursorrules
```

### GitHub Copilot
1. Create `.github/copilot-instructions.md` in your repo
2. Paste the contents of `00_AI_SYSTEM_PROMPT.md`
3. Append relevant layer files

### ChatGPT / GPT-4
1. Create a Custom GPT with `00_AI_SYSTEM_PROMPT.md` as the system prompt
2. Upload specific layer files as knowledge files for the GPT
3. For one-off sessions: paste the system prompt + relevant layer file before your question

### Windsurf / Codeium
1. Create `.windsurfrules` in your project root
2. Paste `00_AI_SYSTEM_PROMPT.md` + relevant layer files

### Any AI with system prompt support
1. Use `00_AI_SYSTEM_PROMPT.md` as the system prompt
2. Include the layer-specific context files relevant to your task as additional context

---

## Recommended Context Combinations by Task

| Task | Register These Files |
|---|---|
| Creating a new module from scratch | OVERVIEW + MODULE + DOMAIN + SERVICE + REPO + CONTROLLER + ANTIPATTERNS |
| Writing domain entities and value objects | OVERVIEW + DOMAIN + ANTIPATTERNS |
| Writing a service with transactions | OVERVIEW + SERVICE + EVENTS + ANTIPATTERNS |
| Writing repository queries | OVERVIEW + REPOSITORY + ANTIPATTERNS |
| Writing a gateway for a third-party API | OVERVIEW + GATEWAY + ERROR_HANDLING |
| Writing HTTP controllers | OVERVIEW + CONTROLLER + SERVICE |
| Setting up security | OVERVIEW + SECURITY + KERNEL |
| Writing background jobs | OVERVIEW + WORKER + SERVICE + ANTIPATTERNS |
| Writing CLI commands | OVERVIEW + CLI + SERVICE |
| Writing tests | OVERVIEW + TESTING + ANTIPATTERNS |
| Debugging an error | OVERVIEW + ERROR_HANDLING + ANTIPATTERNS |
| Code review | OVERVIEW + All files + ANTIPATTERNS |
| Starting from scratch (all context) | ALL FILES |

---

## Tips for Best Results

1. **Register `13_ANTIPATTERNS.md` every time** — it prevents the most common AI mistakes
2. **Include your actual code** alongside the context — AI works better with real examples
3. **Name the specific layer** in your prompt: *"I'm writing a Repository layer class..."*
4. **If the AI suggests a pattern that feels wrong**, paste the relevant anti-pattern section
5. **For code review**, paste the code AND ask: *"Does this violate any AlfacodeTeam PhpServicePlatform access rules?"*

---

## Quick Reference Card

```
Domain/         → final class, private ctor, static factory, zero external imports
Service/        → transaction + collector + eventBus, events AFTER commit
Repository/     → DatabasePort only, tenant_id always, translate \PDOException
Gateway/        → vendor SDK only, translate all vendor exceptions
Controller/     → 3 lines: DTO → service → Response

Never:
  - Import another module's Repository (use its Contract)
  - Dispatch events inside try block
  - Float for money (use Money::of() with cents)
  - Static properties in services (FPM leaks)
  - Routes in PHP (module.json only)
  - Undeclared config vars (boot fails)
```
