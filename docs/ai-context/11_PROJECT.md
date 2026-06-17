# AlfacodeTeam PhpServicePlatform — Project Layer Context

> The Project layer contains no business logic. It wires kernel contracts to infrastructure adapters and chooses which business modules are active per project.

---

## Current Project Bootstrap Architecture

The repository now uses inheritance-safe project bootstrapping:

- Shared base builder: `app/bootstrap/base.php` (returns an unbuilt `Kernel` builder)
- Per-project bootstrap: `projects/{project}/bootstrap/app.php` (extends base and calls `->build()`)
- Backward-compatible shim: `bootstrap/app.php` delegates to `projects/admin/bootstrap/app.php`
- Runtime selection: entry points resolve `HKM_PROJECT` (default: `admin`) and load `projects/{HKM_PROJECT}/bootstrap/app.php`, falling back to `bootstrap/app.php`

---

## Why This Shape

The kernel freezes `CoreContainer` when it materializes (the first entry-point call), not in `build()`. Inherited projects must still share the builder, not a built kernel instance — each project finalizes its own ports/modules with its own `->build()`.

This allows:

- one shared admin base in `app/`
- many child projects with their own module sets
- identical entry points reused across projects

---

## Builder Semantics (Inheritance-Safe)

`Kernel` builder methods are additive so child projects can extend base config safely:

- `withPorts([...])`: merges with existing bindings (later keys override earlier ones)
- `withSecurity([...])`: appends layers (base first, project additions later)
- `withModules([...])`: appends and de-duplicates module class names preserving order

---

## File Layout (As Implemented)

```text
app/
├── Infrastructure/
│   ├── InMemoryCache.php
│   └── PdoDatabase.php
├── bootstrap/
│   └── base.php
├── api/server.php
├── cli/run.php
├── worker/run.php
└── public_html/index.php

projects/
└── admin/
    └── bootstrap/app.php

bootstrap/
└── app.php   # legacy shim
```

---

## Base Builder Pattern

```php
// app/bootstrap/base.php (shared defaults, NO ->build())
return Kernel::configure()
    ->withBasePath(dirname(__DIR__, 2))
    ->withPorts([
        DatabasePort::class => new PdoDatabase(...),
        CachePort::class    => new InMemoryCache(),
    ])
    ->withSecurity([
        new CsrfTokenLayer(exemptPaths: ['/api']),
    ]);
```

---

## Project Bootstrap Pattern

```php
// projects/admin/bootstrap/app.php
/** @var Kernel $builder */
$builder = require __DIR__ . '/../../../app/bootstrap/base.php';

return $builder
    ->withModules([
        TaskModule::class,
    ])
    ->build();
```

---

## Entry Point Resolution Pattern

All entry points in `app/` follow this runtime bootstrap selection logic. Note the
fixed order: resolve the project, **load the environment, install the error net, THEN
require the kernel bootstrap** (so a pre-kernel failure is caught and cannot leak):

```php
$rootPath = dirname(__DIR__, 2);
$domain   = EntryHelpers::resolveDomain($rootPath, $host);   // HTTP only; null in CLI/worker
$project  = (string) (getenv('HKM_PROJECT') ?: 'admin');     // ← legitimate pre-env getenv

LoadEnvironment::load($rootPath, $domain, $argv);            // 1. .env cascade → $_ENV
ErrorGuard::install($rootPath . '/projects/' . $project . '/var/logs/errors.log'); // 2. error net

$kernel = require EntryHelpers::bootstrapPathFor($rootPath, $project);  // 3. kernel
```

`HKM_PROJECT` is read with `getenv()` on purpose — it selects which project to boot and
is a genuine OS/server variable evaluated *before* `LoadEnvironment` runs. Everything the
kernel and modules read afterwards must use the `env()` helper, not `getenv()` (see
`app/Bootstrap/Environment/` and the env() rule in CLAUDE.md).

Applied to:

- `app/api/server.php` (env + guard installed once per worker in `workerStart`; guard is ini-only)
- `app/cli/run.php`
- `app/worker/run.php`
- `app/public_html/index.php`

---

## Rules For Future Project Work

- Keep business logic out of `app/`, `bootstrap/`, and project bootstrap files
- Put only port/adapters/security/module lists in bootstrap wiring
- Add new projects under `projects/{name}/bootstrap/app.php`
- Ensure module classes listed in `withModules()` have valid `module.json`
- Prefer extending `app/bootstrap/base.php` over copy-pasting full kernel wiring
